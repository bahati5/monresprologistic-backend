<?php

namespace App\Services;

use App\Enums\AssistedPurchaseStatus;
use App\Jobs\ScrapeProductJob;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InboundEmailParserService
{
    /**
     * Parse an inbound email and create an assisted purchase request.
     *
     * @param array{from: string, from_name: ?string, subject: string, body_plain: string, body_html: ?string} $emailData
     */
    public function parse(array $emailData): ?AssistedPurchase
    {
        $senderEmail = strtolower(trim($emailData['from'] ?? ''));
        $senderName = $emailData['from_name'] ?? '';
        $body = $emailData['body_plain'] ?? '';
        $subject = $emailData['subject'] ?? '';

        if (!$senderEmail || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            Log::warning('InboundEmail: invalid sender', ['from' => $senderEmail]);
            return null;
        }

        $urls = $this->extractUrls($body);
        if (empty($urls)) {
            $urls = $this->extractUrls($emailData['body_html'] ?? '');
        }

        if (empty($urls)) {
            Log::info('InboundEmail: no product URLs found', ['from' => $senderEmail, 'subject' => $subject]);
            return null;
        }

        $prospectResolver = app(ProspectResolverService::class);
        $resolved = $prospectResolver->resolve($senderEmail, $senderName ?: $senderEmail);

        $cleanBody = $this->cleanEmailBody($body);
        $firstUrl = $urls[0];
        $domain = $this->extractDomain($firstUrl);

        $purchase = AssistedPurchase::create([
            'user_id' => $resolved['user_id'],
            'status' => AssistedPurchaseStatus::PENDING_QUOTE,
            'product_url' => $firstUrl,
            'article_label' => $domain,
            'quantity' => 1,
            'notes' => $cleanBody,
            'line_notes' => json_encode([
                'source' => 'email_inbound',
                'full_name' => $senderName,
                'email' => $senderEmail,
                'subject' => $subject,
                'is_prospect' => $resolved['is_prospect'],
            ]),
        ]);

        foreach ($urls as $url) {
            $item = AssistedPurchaseItem::create([
                'assisted_purchase_id' => $purchase->id,
                'url' => $url,
                'name' => $this->extractDomain($url),
                'quantity' => 1,
                'unit_price' => 0,
            ]);

            $cacheKey = 'scrape_email_' . $item->id . '_' . md5($url);
            ScrapeProductJob::dispatch($url, $cacheKey);
        }

        Log::info('InboundEmail: purchase created', [
            'purchase_id' => $purchase->id,
            'from' => $senderEmail,
            'urls_count' => count($urls),
        ]);

        return $purchase;
    }

    /**
     * Extract product URLs from text, filtering out common non-product domains.
     *
     * @return list<string>
     */
    private function extractUrls(string $text): array
    {
        preg_match_all(
            '/https?:\/\/[^\s<>"\']+/i',
            $text,
            $matches
        );

        $urls = array_unique($matches[0] ?? []);

        $excluded = ['monrespro.cd', 'monrespro.com', 'google.com', 'facebook.com', 'gmail.com', 'outlook.com'];

        return array_values(array_filter($urls, function (string $url) use ($excluded) {
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            foreach ($excluded as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return false;
                }
            }
            return true;
        }));
    }

    private function cleanEmailBody(string $body): string
    {
        $body = preg_replace('/^>.*$/m', '', $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);

        return trim(Str::limit($body, 2000));
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        return str_replace('www.', '', $host);
    }
}

<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\DTOs\Prescription;
use Sisly\Enums\ContentType;

class PrescriptionParser
{
    /**
     * Extract and parse the prescription block from the LLM response text.
     *
     * Returns an array containing the cleaned response text (with the block removed)
     * and a parsed Prescription DTO (or null if no valid block is found).
     *
     * @return array{text: string, prescription: ?Prescription}
     */
    public function parse(string $text): array
    {
        // Regex to extract ```sisly { ... } ``` block
        $pattern = '/```sisly\s*(\{.*?\})\s*```/s';

        if (preg_match($pattern, $text, $matches)) {
            $jsonStr = trim($matches[1]);
            
            try {
                $data = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);

                if (isset($data['content_type']) && isset($data['reason'])) {
                    // Validate content_type
                    $contentTypeStr = (string) $data['content_type'];
                    $contentType = ContentType::tryFrom($contentTypeStr);

                    if ($contentType !== null) {
                        // Strip the sisly block from the original text
                        $cleanText = trim(preg_replace($pattern, '', $text));
                        
                        return [
                            'text' => $cleanText,
                            'prescription' => new Prescription(
                                contentType: $contentType,
                                reason: (string) $data['reason']
                            ),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Silently ignore parsing errors, returning null for prescription
            }
        }

        return [
            'text' => $text,
            'prescription' => null,
        ];
    }
}

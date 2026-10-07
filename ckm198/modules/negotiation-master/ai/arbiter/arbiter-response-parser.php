<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class ArbiterResponseParser {
    private static function decodeCandidate(string $candidate): ?array {
        $decoded = json_decode(trim($candidate), true);
        if (is_array($decoded)) { return $decoded; }
        // AI Puffer can serialize model text once more, so the first decode may be a JSON string.
        if (is_string($decoded)) {
            $decoded2 = json_decode(trim($decoded), true);
            if (is_array($decoded2)) { return $decoded2; }
        }
        return null;
    }

    private static function extractBalancedObject(string $text): ?array {
        $length = strlen($text);
        for ($start = 0; $start < $length; $start++) {
            if ($text[$start] !== '{') { continue; }
            $depth = 0; $quoted = false; $escaped = false;
            for ($i = $start; $i < $length; $i++) {
                $ch = $text[$i];
                if ($quoted) {
                    if ($escaped) { $escaped = false; continue; }
                    if ($ch === '\\') { $escaped = true; continue; }
                    if ($ch === '"') { $quoted = false; }
                    continue;
                }
                if ($ch === '"') { $quoted = true; continue; }
                if ($ch === '{') { $depth++; continue; }
                if ($ch !== '}') { continue; }
                $depth--;
                if ($depth === 0) {
                    $decoded = self::decodeCandidate(substr($text, $start, $i - $start + 1));
                    if (is_array($decoded)) { return $decoded; }
                    break;
                }
                if ($depth < 0) { break; }
            }
        }
        return null;
    }

    public function parse(string $raw): array {
        $raw = trim(str_replace("\0", '', preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw));
        if ($raw === '') { throw new \UnexpectedValueException('Arbiter returned an empty response.'); }

        if (($direct = self::decodeCandidate($raw)) !== null) { return $direct; }

        // Accept harmless markdown wrappers without repairing model data.
        if (preg_match_all('/```(?:json)?\s*([\s\S]*?)\s*```/iu', $raw, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (($decoded = self::decodeCandidate((string)$block)) !== null) { return $decoded; }
            }
        }

        if (($balanced = self::extractBalancedObject($raw)) !== null) { return $balanced; }
        throw new \UnexpectedValueException('Arbiter JSON is invalid.');
    }
}

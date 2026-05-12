<?php
class TTW_Auto_Linker {
    private static array $terms = [
        'ChatGPT' => 'https://chat.openai.com',
        'OpenAI' => 'https://openai.com',
        'Claude' => 'https://claude.ai',
        'Anthropic' => 'https://www.anthropic.com',
    ];

    public static function add_links( string $html ): string {
        $hrefs = [];
        $html_temp = preg_replace_callback('/href="[^"]*"/i', function($m) use (&$hrefs){
            $idx = count($hrefs); $hrefs[$idx] = $m[0]; return '___HREF_'.$idx.'___';
        }, $html);

        $text_parts = preg_split('/(<[^>]+>)/is', $html_temp, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = '';
        $linked_terms = [];

        foreach ($text_parts as $i => $part) {
            if ($i % 2 !== 0) { $result .= $part; continue; }
            $text = $part;
            foreach (self::$terms as $term => $url) {
                $term_key = mb_strtolower($term);
                if (isset($linked_terms[$term_key])) continue;
                $pattern = '/\b(' . preg_quote($term, '/') . ')\b(?![^<]*>)(?![^<]*<\/a>)/i';
                $replacement = '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow noopener">$1</a>';
                $new_text = preg_replace($pattern, $replacement, $text, 1, $count);
                if ($count > 0) { $linked_terms[$term_key] = true; $text = $new_text; }
            }
            $result .= $text;
        }

        foreach ($hrefs as $idx => $href) {
            $result = str_replace('___HREF_'.$idx.'___', $href, $result);
        }
        return $result;
    }
}

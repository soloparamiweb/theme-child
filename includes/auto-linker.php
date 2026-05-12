<?php

/**
 * Añade enlaces automáticos a nombres de herramientas y marcas mencionadas en el contenido
 */
class TTW_Auto_Linker {

    private static array $terms = [
        // IA y LLMs
        'Claude'           => 'https://claude.ai',
        'Claude Code'      => 'https://docs.anthropic.com/en/docs/build-with-claude/claude-code',
        'ChatGPT'          => 'https://chat.openai.com',
        'GPT-4'            => 'https://openai.com/gpt-4',
        'GPT-4o'           => 'https://openai.com/index/gpt-4o/',
        'Gemini'           => 'https://gemini.google.com',
        'Copilot'          => 'https://copilot.microsoft.com',
        'Perplexity'       => 'https://www.perplexity.ai',
        'Anthropic'        => 'https://www.anthropic.com',
        'OpenAI'           => 'https://openai.com',
        'Midjourney'       => 'https://www.midjourney.com',
        'Stable Diffusion' => 'https://stability.ai',
        'DALL-E'           => 'https://openai.com/dall-e',
        
        // Lenguajes y frameworks
        'Python'           => 'https://www.python.org',
        'JavaScript'       => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript',
        'TypeScript'       => 'https://www.typescriptlang.org',
        'Node.js'          => 'https://nodejs.org',
        'React'            => 'https://react.dev',
        'Vue'              => 'https://vuejs.org',
        'Next.js'          => 'https://nextjs.org',
        
        // Herramientas dev y cloud
        'GitHub'           => 'https://github.com',
        'VS Code'          => 'https://code.visualstudio.com',
        'Docker'           => 'https://www.docker.com',
        'Kubernetes'       => 'https://kubernetes.io',
        'Terraform'        => 'https://www.terraform.io',
        'AWS'              => 'https://aws.amazon.com',
        'Azure'            => 'https://azure.microsoft.com',
        'Google Cloud'     => 'https://cloud.google.com',
        
        // Frameworks ML
        'TensorFlow'       => 'https://www.tensorflow.org',
        'PyTorch'          => 'https://pytorch.org',
        'LangChain'        => 'https://www.langchain.com',
        'Hugging Face'     => 'https://huggingface.co',
        
        // Scraping y automatización
        'Scrapy'           => 'https://scrapy.org',
        'Selenium'         => 'https://www.selenium.dev',
        'BeautifulSoup'    => 'https://www.crummy.com/software/BeautifulSoup/',
        'Beautiful Soup'   => 'https://www.crummy.com/software/BeautifulSoup/',
        'Apify'            => 'https://apify.com',
        'Puppeteer'        => 'https://pptr.dev',
        
        // SaaS de análisis y competitor intelligence
        'SEMrush'          => 'https://www.semrush.com',
        'Ahrefs'           => 'https://ahrefs.com',
        'Brandwatch'       => 'https://www.brandwatch.com',
        'SimilarWeb'       => 'https://www.similarweb.com',
        'Crayon'           => 'https://www.crayon.com',
        'Kompyte'          => 'https://www.kompyte.com',
        'Crunchbase'       => 'https://www.crunchbase.com',
        
        // Visualización y automation
        'Tableau'          => 'https://www.tableau.com',
        'Zapier'           => 'https://zapier.com',
        'Google Sheets'    => 'https://docs.google.com/spreadsheets',
        'Power BI'         => 'https://powerbi.microsoft.com',
        'Looker'           => 'https://looker.com',
        
        // APIs y servicios
        'REST API'         => 'https://www.redhat.com/en/topics/api/what-is-a-rest-api',
        'GraphQL'          => 'https://graphql.org',
        'JSON'             => 'https://www.json.org/json-es.html',
        
        // Otros
        'Notion'           => 'https://www.notion.so',
        'Slack'            => 'https://slack.com',
        'Figma'            => 'https://www.figma.com',
        'Linear'           => 'https://linear.app',
        'Excel'            => 'https://support.microsoft.com/es-es/excel',
    ];

    /**
     * Procesa el HTML y añade enlaces a términos conocidos
     */
    public static function add_links( string $html ): string {
        // Reemplazar temporalmente los hrefs para protegerlos
        $hrefs = [];
        $html_temp = preg_replace_callback(
            '/href="[^"]*"/i',
            function( $match ) use ( &$hrefs ) {
                $idx = count( $hrefs );
                $hrefs[$idx] = $match[0];
                return '___HREF_' . $idx . '___';
            },
            $html
        );

        // Ahora procesar cada término en todo el texto (sin límite)
        $text_parts = preg_split( '/(<[^>]+>)/is', $html_temp, -1, PREG_SPLIT_DELIM_CAPTURE );
        
        $result = '';
        
        foreach ( $text_parts as $i => $part ) {
            // Si es una etiqueta HTML, preservarla
            if ( $i % 2 !== 0 ) {
                $result .= $part;
                continue;
            }
            
            // Es texto plano, procesar todos los términos
            $text = $part;
            
            foreach ( self::$terms as $term => $url ) {
                // Reemplazar TODAS las ocurrencias (sin límite) que no estén ya dentro de un enlace
                $pattern = '/\b(' . preg_quote( $term, '/' ) . ')\b(?![^<]*>)(?![^<]*<\/a>)/i';
                $replacement = '<a href="' . esc_url( $url ) . '" target="_blank" rel="nofollow noopener">' . $term . '</a>';
                $text = preg_replace( $pattern, $replacement, $text );
            }
            
            $result .= $text;
        }

        // Restaurar los hrefs originales
        foreach ( $hrefs as $idx => $href ) {
            $result = str_replace( '___HREF_' . $idx . '___', $href, $result );
        }

        return $result;
    }
}

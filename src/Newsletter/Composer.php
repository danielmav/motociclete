<?php

declare(strict_types=1);

namespace App\Newsletter;

/**
 * Compunerea unui mesaj: Content rezolvă datele din formular, Images mută imaginile
 * produselor pe situl nostru, Renderer produce HTML-ul și textul. Excepțiile lui
 * Content (date lipsă, produs negăsit) ajung la apelant.
 */
final class Composer
{
    public function __construct(private Content $content, private Renderer $renderer, private ?Images $images = null)
    {
    }

    /**
     * @param array<string,mixed> $input forma formularului (subiect, preheader, stire, modele, produse)
     * @param array<string,mixed> $brand datele de contact din footer
     * @return array{html:string,text:string,warnings:array<int,string>}
     */
    public function compose(string $type, array $input, string $campaign, array $brand): array
    {
        $content = $this->content->resolve($type, $input);
        $warnings = $this->content->warnings();
        if ($this->images !== null) {
            foreach ($content['products'] as $i => $product) {
                $content['products'][$i]['image'] = $this->images->localize((string) $product['image']);
            }
            $warnings = array_merge($warnings, $this->images->warnings());
        }
        $out = $this->renderer->render($content, $campaign, $brand);
        return ['html' => $out['html'], 'text' => $out['text'], 'warnings' => array_values($warnings)];
    }
}

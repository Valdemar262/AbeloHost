<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use Smarty\Smarty;

final class View
{
    private readonly Smarty $smarty;

    public function __construct(string $templateDirectory, string $runtimeDirectory)
    {
        foreach (['compile', 'cache'] as $directory) {
            $path = $runtimeDirectory . '/' . $directory;

            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new RuntimeException('Cannot create Smarty runtime directory.');
            }

            if (!is_writable($path)) {
                throw new RuntimeException('Smarty runtime directory is not writable.');
            }
        }

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir($templateDirectory);
        $this->smarty->setCompileDir($runtimeDirectory . '/compile');
        $this->smarty->setCacheDir($runtimeDirectory . '/cache');
        $this->smarty->setEscapeHtml(true);
        $this->smarty->setCaching(Smarty::CACHING_OFF);
    }

    public function assign(string $name, mixed $value): void
    {
        $this->smarty->assign($name, $value);
    }

    public function render(string $template, array $data = []): string
    {
        $page = $this->smarty->createTemplate($template);
        $page->assign($data);

        return $page->fetch();
    }
}

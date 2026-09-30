<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repository\CategoryRepository;
use App\View;

final class HomeController
{
    public function __construct(
        private readonly View $view,
        private readonly CategoryRepository $categories,
    ) {
    }

    public function index(): Response
    {
        return new Response(
            $this->view->render('pages/home.tpl', [
                'pageTitle' => 'Главная',
                'categories' => $this->categories->findWithLatestPosts(gmdate('Y-m-d H:i:s')),
            ]),
        );
    }
}

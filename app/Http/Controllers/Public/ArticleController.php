<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\WordPressContent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Articles written in WordPress, read on this subdomain.
 *
 * The marketing site is the editor; this is the reader. Nothing is stored here, so a post is live on
 * the subdomain as soon as WordPress publishes it and the 30-minute cache turns over.
 *
 * When there is nothing publishable — which is the case while the WordPress side still only holds
 * Elementor's placeholder posts — the index renders an honest empty state and asks not to be
 * indexed, so an empty page never enters the search index.
 */
class ArticleController extends Controller
{
    public function __construct(private readonly WordPressContent $wordpress) {}

    public function index(): View
    {
        $articles = $this->wordpress->posts(12);

        return view('marketing.articles.index', [
            'articles' => $articles,
            'noindex' => $articles->isEmpty(),
        ]);
    }

    public function show(Request $request): View
    {
        $article = $this->wordpress->post((string) $request->route('slug'));

        abort_if($article === null, 404);

        return view('marketing.articles.show', ['article' => $article]);
    }
}

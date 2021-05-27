<?php

namespace App\Http\Controllers;

use App\Post;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Spatie\Tags\Tag;
use Storage;

class BlogController extends Controller
{
    public function index(Request $request, string $tag = null)
    {
        if ($tag !== null) {
            $tagModel = Tag::query()->where('slug->' . app()->getLocale(), $tag)->first();
            if ($tagModel === null) {
                return redirect(route('blog'), 301);
            }
        }

        $search = trim((string)$request->input('search'));
        $page = trim((string)$request->input('page'));

        $paginator = $this->buildQueryFromRequest($search, $tag);
        $posts = $this->getPostsFromPaginator($paginator);

        return view('blog', [
            'posts' => $posts,
            'paginator' => $paginator,
            'featuredPost' => Post::getFeatured(),
            'pinnedPosts' => Collection::make([]),
            'data' => [
                'tag' => $tag,
                'page' => $page,
                'search' => $search,
            ],
            'topMenuData' => $this->getTopMenuData($tag),
        ]);
    }

    public function search(Request $request, string $tag = null)
    {
        $search = trim((string)$request->input('search'));
        $paginator = $this->buildQueryFromRequest($search, $tag);
        $posts = $this->getPostsFromPaginator($paginator);

        return view('partials.blog.posts', [
            'posts' => $posts,
            'paginator' => $paginator,
        ]);
    }

    private function buildQueryFromRequest(string $search, $tag)
    {
        $query = Post::query()->published();

        // Search.
        if ($search !== '') {
            $query->whereRaw('LOWER(title) LIKE ?', ['%' . strtolower($search) . '%']);
        }

        // Tag.
        if (!empty($tag)) {
            $query->whereHas('tags', fn($query) => $query->where('slug->' . app()->getLocale(), $tag));
        }

        return $query->select('id')
            ->latestPublished()
            ->paginate(5);
    }

    private function getPostsFromPaginator($paginator): Collection
    {
        $postsIds = data_get($paginator->items(), '*.id');
        if (!is_array($postsIds) || count($postsIds) === 0) {
            return Collection::make([]);
        }

        return Post::whereIn('id', $postsIds)->latestPublished()->with('tags')->get();
    }

    public function post($slug)
    {
        /** @var Post * */
        $post = Post::getPublishedFirstOrFailBySlug($slug);
        $relatedPosts = Post::getPublishedRelated($post, 4);

        $imageSrc = null;
        if ($post->featured_thumbnail && Storage::disk('public')->exists($post->featured_thumbnail)) {
            $imageSrc = Storage::url($post->featured_thumbnail);
        } elseif ($post->thumbnail && Storage::disk('public')->exists($post->thumbnail)) {
            $imageSrc = Storage::url($post->thumbnail);
        }

        return view('blog-post', [
            'post' => $post,
            'relatedPosts' => $relatedPosts,
            'imageSrc' => $imageSrc,
        ]);
    }

    private function getTopMenuData(string $currentTag = null): array
    {
        $menuData = [
            [
                'name' => __('Explore'),
                'link' => route('blog'),
                'current' => ($currentTag === null),
            ]
        ];

        $tags = Tag::all();
        foreach ($tags as $tag) {
            $menuData[] = [
                'name' => $tag->name,
                'link' => route('blog', ['tag' => $tag->slug]),
                'current' => ($currentTag == $tag->slug),
            ];
        }

        return $menuData;
    }
}

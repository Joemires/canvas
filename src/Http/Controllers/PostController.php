<?php

namespace Canvas\Http\Controllers;

use Canvas\Http\Requests\PostRequest;
use Canvas\Models\Post;
use Canvas\Models\Tag;
use Canvas\Models\Topic;
use Canvas\Services\StatsAggregator;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

class PostController extends Controller
{
    private $postModel;
    private $tagModel;
    private $topicModel;

    public function __construct()
    {
        $this->postModel  = app()->make(Post::class);
        $this->tagModel   = app()->make(Tag::class);
        $this->topicModel = app()->make(Topic::class);
    }
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $posts = $this->postModel
                    ->query()
                    ->select('id', 'title', 'summary', 'featured_image', 'published_at', 'created_at', 'updated_at')
                    ->when(request()->user('canvas')->isContributor || request()->query('scope', 'user') != 'all', function (Builder $query) {
                        return $query->where('user_id', request()->user('canvas')->id);
                    }, function (Builder $query) {
                        return $query;
                    })
                    ->when(request()->query('type', 'published') != 'draft', function (Builder $query) {
                        return $query->published();
                    }, function (Builder $query) {
                        return $query->draft();
                    })
                    ->latest()
                    ->withCount('views')
                    ->paginate();

        // TODO: The count() queries here are duplicated

        $draftCount = $this->postModel
                        ->query()
                        ->when(request()->user('canvas')->isContributor || request()->query('scope', 'user') != 'all', function (Builder $query) {
                            return $query->where('user_id', request()->user('canvas')->id);
                        }, function (Builder $query) {
                            return $query;
                        })
                        ->draft()
                        ->count();

        $publishedCount = $this->postModel
                            ->query()
                            ->when(request()->user('canvas')->isContributor || request()->query('scope', 'user') != 'all', function (Builder $query) {
                                return $query->where('user_id', request()->user('canvas')->id);
                            }, function (Builder $query) {
                                return $query;
                            })
                            ->published()
                            ->count();

        return response()->json([
            'posts' => $posts,
            'draftCount' => $draftCount,
            'publishedCount' => $publishedCount,
        ], 200);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return JsonResponse
     */
    public function create(): JsonResponse
    {
        $uuid = Uuid::uuid4();

        return response()->json([
            'post' => $this->postModel->query()->make([
                'id' => $uuid->toString(),
                'slug' => "post-{$uuid->toString()}",
            ]),
            'tags' => $this->tagModel->query()->get(['name', 'slug']),
            'topics' => $this->topicModel->query()->get(['name', 'slug']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  PostRequest  $request
     * @param  $id
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function store(PostRequest $request, $id): JsonResponse
    {
        $data = $request->validated();

        $post = $this->postModel
                    ->query()
                    ->when($request->user('canvas')->isContributor, function (Builder $query) {
                        return $query->where('user_id', request()->user('canvas')->id);
                    }, function (Builder $query) {
                        return $query;
                    })
                    ->with('tags', 'topic')
                    ->find($id);

        if (! $post) {
            $post = $this->postModel->fill(['id' => $id]);
        }

        $post->fill($data);

        $post->user_id = $post->user_id ?? request()->user('canvas')->id;

        $post->save();

        $tags = $this->tagModel->query()->get(['id', 'name', 'slug']);
        $topics = $this->topicModel->query()->get(['id', 'name', 'slug']);

        $tagsToSync = collect($request->input('tags', []))->map(function ($item) use ($tags) {
            $tag = $tags->firstWhere('slug', $item['slug']);

            if (! $tag) {
                $tag = $this->tagModel->create([
                    'id' => $id = Uuid::uuid4()->toString(),
                    'name' => $item['name'],
                    'slug' => $item['slug'],
                    'user_id' => request()->user('canvas')->id,
                ]);
            }

            return (string) $tag->id;
        })->toArray();

        $topicToSync = collect($request->input('topic', []))->map(function ($item) use ($topics) {
            $topic = $topics->firstWhere('slug', $item['slug']);

            if (! $topic) {
                $topic = $this->topicModel->create([
                    'id' => $id = Uuid::uuid4()->toString(),
                    'name' => $item['name'],
                    'slug' => $item['slug'],
                    'user_id' => request()->user('canvas')->id,
                ]);
            }

            return (string) $topic->id;
        })->toArray();

        $post->tags()->sync($tagsToSync);

        $post->topic()->sync($topicToSync);

        return response()->json($post->refresh(), 201);
    }

    /**
     * Display the specified resource.
     *
     * @param  $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $post = $this->postModel
                    ->query()
                    ->when(request()->user('canvas')->isContributor, function (Builder $query) {
                        return $query->where('user_id', request()->user('canvas')->id);
                    }, function (Builder $query) {
                        return $query;
                    })
                    ->with('tags:name,slug', 'topic:name,slug')
                    ->findOrFail($id);

        return response()->json([
            'post' => $post,
            'tags' => $this->tagModel->query()->get(['name', 'slug']),
            'topics' => $this->topicModel->query()->get(['name', 'slug']),
        ]);
    }

    /**
     * Display stats for the specified resource.
     *
     * @param  string  $id
     * @return JsonResponse
     */
    public function stats(string $id): JsonResponse
    {
        $post = $this->postModel
                    ->query()
                    ->when(request()->user('canvas')->isContributor, function (Builder $query) {
                        return $query->where('user_id', request()->user('canvas')->id);
                    }, function (Builder $query) {
                        return $query;
                    })
                    ->published()
                    ->findOrFail($id);

        $stats = app()->make(StatsAggregator::class, ['user' => request()->user('canvas')]);

        $results = $stats->getStatsForPost($post);

        return response()->json($results);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  $id
     * @return mixed
     *
     * @throws Exception
     */
    public function destroy($id)
    {
        $post = $this->postModel
                    ->query()
                    ->when(request()->user('canvas')->isContributor, function (Builder $query) {
                        return $query->where('user_id', request()->user('canvas')->id);
                    }, function (Builder $query) {
                        return $query;
                    })
                    ->findOrFail($id);

        $post->delete();

        return response()->json(null, 204);
    }
}

<?php

use App\Jobs\PublishPost;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function post(string $status, string $when): Post
{
    return Post::factory()->create([
        'platform' => 'facebook',
        'status' => $status,
        'scheduled_at' => now()->modify($when),
    ]);
}

it('queues only due scheduled posts', function () {
    Queue::fake();
    $due = post('scheduled', '-1 minute');
    $future = post('scheduled', '+1 hour');
    $draft = post('draft', '-1 minute');

    $this->artisan('posts:publish-due')->assertSuccessful();

    Queue::assertPushed(PublishPost::class, 1);
    expect($due->fresh()->status)->toBe('queued')
        ->and($future->fresh()->status)->toBe('scheduled')
        ->and($draft->fresh()->status)->toBe('draft');
});

it('does not queue a post twice', function () {
    Queue::fake();
    post('scheduled', '-1 minute');

    $this->artisan('posts:publish-due');
    $this->artisan('posts:publish-due');

    Queue::assertPushed(PublishPost::class, 1);
});

it('is scheduled every five minutes', function () {
    $this->artisan('schedule:list')->expectsOutputToContain('*/5 * * * *')->assertSuccessful();
});

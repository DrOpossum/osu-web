<?php
/**
 *    Copyright (c) ppy Pty Ltd <contact@ppy.sh>.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\Controllers\Chat;

use App\Models\Chat;
use App\Models\User;
use App\Models\UserAccountHistory;
use App\Models\UserRelation;
use Faker;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    // Need to disable transactions for these tests otherwise the cross-database queries being used fail.
    protected $connectionsToTransact = [];

    protected static $faker;

    public static function setUpBeforeClass(): void
    {
        self::$faker = Faker\Factory::create();
    }

    //region POST /chat/new - Create New PM
    public function testCreatePM() // success
    {
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);
    }

    public function testCreatePMWhenAlreadyExists() // success
    {
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // should return existing conversation and not error
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);
    }

    public function testCreatePMWhenGuest() // fail
    {
        $this->json(
            'POST',
            route('api.chat.new'),
            [
                'target_id' => $this->anotherUser->user_id,
                'message' => self::$faker->sentence(),
            ]
        )->assertStatus(401);
    }

    public function testCreatePMWhenBlocked() // fail
    {
        factory(UserRelation::class)->states('block')->create([
            'user_id' => $this->anotherUser->user_id,
            'zebra_id' => $this->user->user_id,
        ]);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenRestricted() // fail
    {
        $restrictedUser = factory(User::class)->states('restricted')->create();

        $this->actAsScopedUser($restrictedUser, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenSilenced() // fail
    {
        // TODO: convert $this->silencedUser to use afterCreatingState after upgrading to Laraval 5.6
        $silencedUser = factory(User::class)->create();
        $silencedUser->accountHistories()->save(
            factory(UserAccountHistory::class)->states('silence')->make()
        );

        $this->actAsScopedUser($silencedUser, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenTargetRestricted() // fail
    {
        $restrictedUser = factory(User::class)->states('restricted')->create();

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $restrictedUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(404);
    }

    public function testCreatePMWithSelf() // fail
    {
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->user->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(422);
    }

    public function testCreatePMWhenFriendsOnlyAndNotFriended() // fail
    {
        $privateUser = factory(User::class)->create(['pm_friends_only' => true]);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $privateUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(403);
    }

    public function testCreatePMWhenFriendsOnlyAndFriended() // success
    {
        $privateUser = factory(User::class)->create(['pm_friends_only' => true]);
        factory(UserRelation::class)->states('friend')->create([
            'user_id' => $privateUser->user_id,
            'zebra_id' => $this->user->user_id,
        ]);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $privateUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);
    }

    //endregion

    //region GET /chat/presence - Get Presence
    public function testChatPresenceWhenGuest() // fail
    {
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(401);
    }

    public function testChatPresence() // success
    {
        $publicChannel = factory(Chat\Channel::class)->states('public')->create();

        // join the channel
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('PUT', route('api.chat.channels.join', [
                'channel' => $publicChannel->channel_id,
                'user' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['channel_id' => $publicChannel->channel_id]);
    }

    public function testChatPresenceHidesBlocked() // success
    {
        // start conversation with $this->anotherUser
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // block $this->anotherUser
        $block = factory(UserRelation::class)->states('block')->create([
            'user_id' => $this->user->user_id,
            'zebra_id' => $this->anotherUser->user_id,
        ]);

        // ensure conversation with $this->anotherUser isn't visible
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonMissing(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);

        // unblock $this->anotherUser
        $block->delete();

        // ensure conversation with $this->anotherUser is visible again
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);
    }

    public function testChatPresenceHidesRestricted() // success
    {
        // start conversation with $this->anotherUser
        $this->actAsScopedUser($this->user, ['*']);
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // restrict $this->anotherUser
        $this->anotherUser->update(['user_warnings' => 1]);

        // ensure conversation with $this->anotherUser isn't visible
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonMissing(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);

        // unrestrict $this->anotherUser
        $this->anotherUser->update(['user_warnings' => 0]);

        // ensure conversation with $this->anotherUser is visible again
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);
    }

    public function testChatPresenceHidesHidden() // success
    {
        // start conversation with $this->anotherUser
        $this->actAsScopedUser($this->user, ['*']);
        $response = $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        $presenceData = $response->decodeResponseJson();
        $channelId = $presenceData['new_channel_id'];

        // leave PM with $this->anotherUser
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('DELETE', route('api.chat.channels.part', [
                'channel' => $channelId,
                'user' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        // ensure conversation with $this->anotherUser isn't visible
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonMissing(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);

        // reopen PM with $this->anotherUser
        $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        // ensure conversation with $this->anotherUser is visible again
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.presence'))
            ->assertStatus(200)
            ->assertJsonFragment(['users' => [
                $this->user->user_id,
                $this->anotherUser->user_id,
            ]]);
    }

    //endregion

    //region GET /chat/updates?since=[message_id] - Get Updates
    public function testChatUpdatesWhenGuest() // fail
    {
        $this->json('GET', route('api.chat.updates'))
            ->assertStatus(401);
    }

    public function testChatUpdatesWithNoNewMessages() // success
    {
        $publicChannel = factory(Chat\Channel::class)->states('public')->create();
        $publicMessage = factory(Chat\Message::class)->create(['channel_id' => $publicChannel->channel_id]);

        // join the channel
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('PUT', route('api.chat.channels.join', [
                'channel' => $publicChannel->channel_id,
                'user' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.updates'), ['since' => $publicMessage->message_id])
            ->assertStatus(204);
    }

    public function testChatUpdates() // success
    {
        $publicChannel = factory(Chat\Channel::class)->states('public')->create();
        $publicMessage = factory(Chat\Message::class)->create(['channel_id' => $publicChannel->channel_id]);

        // join channel
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('PUT', route('api.chat.channels.join', [
                'channel' => $publicChannel->channel_id,
                'user' => $this->user->user_id,
            ]))
            ->assertStatus(204);

        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.updates'), ['since' => 0])
            ->assertStatus(200)
            ->assertJsonFragment(['content' => $publicMessage->content]);
    }

    public function testChatUpdatesHidesRestrictedUserMessages() // success
    {
        // create PM
        $this->actAsScopedUser($this->user, ['*']);
        $response = $this->json(
                'POST',
                route('api.chat.new'),
                [
                    'target_id' => $this->anotherUser->user_id,
                    'message' => self::$faker->sentence(),
                ]
            )->assertStatus(200);

        $presenceData = $response->decodeResponseJson();
        $channelId = $presenceData['new_channel_id'];

        // create reply
        $publicMessage = factory(Chat\Message::class)->create([
            'user_id' => $this->anotherUser->user_id,
            'channel_id' => $channelId,
        ]);

        // ensure reply is visible
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.updates'), ['since' => 0])
            ->assertStatus(200)
            ->assertJsonFragment(['content' => $publicMessage->content]);

        // restrict $this->anotherUser
        $this->anotherUser->update(['user_warnings' => 1]);

        // ensure reply is no longer visible
        $this->actAsScopedUser($this->user, ['*']);
        $this->json('GET', route('api.chat.updates'), ['since' => 0])
            ->assertStatus(204);
    }

    //endregion

    protected function setUp(): void
    {
        parent::setUp();

        $trx = [];
        $db = $this->app->make('db');
        foreach (array_keys(config('database.connections')) as $name) {
            $connection = $db->connection($name);

            // connections with different names but to the same database share the same pdo connection.
            $id = $connection->select('SELECT CONNECTION_ID() as connection_id')[0]->connection_id;
            // Avoid setting isolation level or starting transaction more than once on a pdo connection.
            if (!in_array($id, $trx, true)) {
                $trx[] = $id;

                // allow uncommitted changes be visible across connections.
                $connection->statement('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
                $connection->beginTransaction();
            }
        }

        $this->user = factory(User::class)->create();
        $this->anotherUser = factory(User::class)->create();
    }
}

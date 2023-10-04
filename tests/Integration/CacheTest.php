<?php

namespace LdapRecord\Tests\Integration;

use Carbon\Carbon;
use LdapRecord\Container;
use LdapRecord\Models\OpenLDAP\User;
use LdapRecord\Query\ArrayCacheStore;
use LdapRecord\Tests\Integration\Concerns\MakesUsers;
use LdapRecord\Tests\Integration\Concerns\SetupTestConnection;
use LdapRecord\Tests\Integration\Concerns\SetupTestOu;
use Psr\SimpleCache\CacheInterface;

class CacheTest extends TestCase
{
    use MakesUsers;
    use SetupTestOu;
    use SetupTestConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setupTestOu();
    }

    protected function resetConnection(array $params = [], CacheInterface $cache = null): void
    {
        Container::flush();

        $connection = $this->makeConnection($params);

        if ($cache) {
            $connection->setCache($cache);
        }

        Container::addConnection($connection);
    }

    protected function getUserCnsFromCache($ttl = 30): array
    {
        $cache = Carbon::now()->addSeconds($ttl);

        return User::cache($cache)
            ->get()
            ->sortBy(fn (User $user) => $user->getName())
            ->map(fn (User $user) => $user->getName())
            ->values()
            ->all();
    }

    public function test_that_results_are_fetched_from_cache()
    {
        $this->resetConnection(cache: new ArrayCacheStore);

        $this->assertEmpty($this->getUserCnsFromCache());

        $user = $this->makeUser($this->ou);
        $user->save();

        $this->assertEmpty($this->getUserCnsFromCache());
    }

    public function test_that_results_are_fetched_from_cache2()
    {
        $this->resetConnection(cache: new ArrayCacheStore);

        $this->makeUser($this->ou, ['cn' => 'foo'])->save();
        $this->assertEquals(['foo'], $this->getUserCnsFromCache());
        $this->makeUser($this->ou, ['cn' => 'bar'])->save();

        $this->assertEquals(['foo'], $this->getUserCnsFromCache());
    }

    public function test_that_results_expire_from_cache()
    {
        $this->resetConnection(cache: new ArrayCacheStore);

        $this->makeUser($this->ou, ['cn' => 'foo'])->save();
        $this->assertEquals(['foo'], $this->getUserCnsFromCache(1));
        $this->makeUser($this->ou, ['cn' => 'bar'])->save();

        sleep(2);

        $this->assertEquals(['bar', 'foo'], $this->getUserCnsFromCache());
    }

    public function test_that_results_stay_in_cache_even_if_connection_is_reset()
    {
        $this->resetConnection(cache: $cache = new ArrayCacheStore);

        $this->makeUser($this->ou, ['cn' => 'foo'])->save();
        $this->assertEquals(['foo'], $this->getUserCnsFromCache());
        $this->makeUser($this->ou, ['cn' => 'bar'])->save();

        $this->resetConnection(cache: $cache);

        $this->assertEquals(['foo'], $this->getUserCnsFromCache());
    }

    public function test_that_results_are_not_reused_if_hostname_changes()
    {
        $this->resetConnection(cache: $cache = new ArrayCacheStore);

        $this->makeUser($this->ou, ['cn' => 'foo'])->save();
        $this->assertEquals(['foo'], $this->getUserCnsFromCache());
        $this->makeUser($this->ou, ['cn' => 'bar'])->save();

        $this->resetConnection(['hosts' => ['127.0.0.1']], $cache);

        $this->assertEquals(['bar', 'foo'], $this->getUserCnsFromCache());
    }

    public function test_custom_cache_keys_can_be_used()
    {
        $this->resetConnection(cache: $cache = new ArrayCacheStore);

        $user = $this->makeUser($this->ou, ['cn' => 'foo']);

        $user->save();

        $user->refresh();

        User::query()->cache(key: 'foo')->get();

        $cached = (new User)->setRawAttributes(
            $cache->get('foo')[0]
        );

        $this->assertEquals($user->toArray(), $cached->toArray());

        User::query()->getCache()->delete('foo');

        $this->assertNull($cache->get('foo'));
    }
}

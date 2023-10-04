<?php

namespace LdapRecord\Tests\Unit\Models\ActiveDirectory;

use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Models\ActiveDirectory\Group;
use LdapRecord\Tests\TestCase;

class GroupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Container::addConnection(new Connection());
    }

    public function test_rid_accessor_works()
    {
        $group = new Group();
        $this->assertEmpty($group->rid);

        $group = new Group(['objectsid' => 'S-1-5']);
        $this->assertEquals(['5'], $group->rid);

        $group = new Group(['objectsid' => 'S-1-5-513']);
        $this->assertEquals(['513'], $group->rid);

        $group = new Group(['objectsid' => 'S-1-5-2141378235-513']);
        $this->assertEquals(['513'], $group->rid);
    }

    public function test_primary_group_members_query()
    {
        $group = new Group();

        $group->setRawAttributes(['objectsid' => 'S-1-5-2141378235-513']);

        $query = $group->primaryGroupMembers()->getRelationQuery()->getUnescapedQuery();

        $this->assertEquals('(primarygroupid=513)', $query);
    }
}

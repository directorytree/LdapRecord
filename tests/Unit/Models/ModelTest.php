<?php

namespace LdapRecord\Tests\Unit\Models;

use Carbon\Carbon;
use DateTimeInterface;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\ContainerException;
use LdapRecord\Models\Attributes\Timestamp;
use LdapRecord\Models\BatchModification;
use LdapRecord\Models\Entry;
use LdapRecord\Models\Model;
use LdapRecord\Tests\TestCase;

class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::clearBootedModels();
    }

    public function test_model_must_have_default_connection()
    {
        $model = new Entry();
        $this->assertFalse($model->exists);
        $this->expectException(ContainerException::class);
        $model->getConnection();
    }

    public function test_model_can_create_new_instances()
    {
        $model = new Entry();
        $new = $model->newInstance(['foo' => 'bar']);
        $this->assertEquals($model->getConnectionName(), $new->getConnectionName());
        $this->assertEquals(['foo' => ['bar']], $new->getAttributes());
        $this->assertFalse($new->exists);
    }

    public function test_boot_is_called_upon_creation()
    {
        new ModelBootingTestStub();
        $this->assertTrue(ModelBootingTestStub::isBooted());
        $this->assertEquals([ModelBootingTestStub::class => true], ModelBootingTestStub::booted());
    }

    public function test_fill()
    {
        $this->assertEmpty((new Entry())->getDn());
        $this->assertEmpty((new Entry())->getAttributes());
        $this->assertNull((new Entry())->getAttribute());
        $this->assertEquals(['foo' => ['bar']], (new Entry(['foo' => 'bar']))->getAttributes());
        $this->assertEquals(['bar' => ['baz']], (new Entry())->fill(['bar' => 'baz'])->getAttributes());
        $this->assertEquals(2, (new Entry())->fill(['foo' => 'bar', 'baz' => 'foo'])->countAttributes());
    }

    public function test_getting_object_guid_key()
    {
        $this->assertEquals('objectguid', (new Entry())->getObjectGuidKey());
    }

    public function test_getting_object_classes()
    {
        $this->assertEmpty((new Entry())->getObjectClasses());
        $this->assertEquals(['foo', 'bar'], (new Entry(['objectclass' => ['foo', 'bar']]))->getObjectClasses());
    }

    public function test_getting_and_setting_dn()
    {
        $model = new Entry();
        $model->setDn('foo');
        $this->assertEquals('foo', $model->getDn());
        $this->assertEquals('foo', (string) $model);
    }

    public function test_getting_parent_dn()
    {
        $model = new Entry();
        $model->setDn('cn=user,dc=acme,dc=org');
        $this->assertEquals('dc=acme,dc=org', $model->getParentDn($model->getDn()));
        $this->assertEquals('dc=acme,dc=org', $model->getParentDn());
        $this->assertEmpty($model->getParentDn(''));
        $this->assertEmpty($model->getParentDn('invalid'));
    }

    public function test_getting_name()
    {
        $model = (new Entry())->setDn('cn=John Doe,dc=acme,dc=org');
        $this->assertEquals('John Doe', $model->getName());

        $model = (new Entry())->setDn('cn=John,dc=acme');
        $this->assertEquals('John', $model->getName());

        $model = (new Entry())->setDn('dn=test=test=test');
        $this->assertEquals('test=test=test', $model->getName());

        $model = (new Entry())->setDn('dc=acme');
        $this->assertEquals('acme', $model->getName());

        // Invalid DN.
        $model = (new Entry())->setDn('invalid');
        $this->assertNull($model->getName());

        $this->assertNull((new Entry())->getName());
    }

    public function test_getting_rdn()
    {
        $model = (new Entry())->setDn('cn=John Doe,dc=acme,dc=org');
        $this->assertEquals('cn=John Doe', $model->getRdn());

        $model = (new Entry())->setDn('cn=John Doe');
        $this->assertEquals('cn=John Doe', $model->getRdn());

        $model = (new Entry())->setDn('dc=acme,dc=org');
        $this->assertEquals('dc=acme', $model->getRdn());

        $this->assertNull((new Entry())->getRdn());
    }

    public function test_creatable_rdn()
    {
        $model = new Entry();
        $this->assertEquals('cn=', $model->getCreatableRdn());

        $model->cn = 'John Doe';
        $this->assertEquals('cn=John Doe', $model->getCreatableRdn());

        $model->cn = '<.,&$^@*$';
        $this->assertEquals('cn=\3c.\2c&$^@*$', $model->getCreatableRdn());

        $this->assertEquals('ou=Name', $model->getCreatableRdn('Name', 'ou'));
    }

    public function test_creatable_dn()
    {
        Container::getNewInstance()->addConnection(new Connection([
            'base_dn' => 'dc=acme,dc=org',
        ]));

        $model = new Entry();
        $model->cn = 'foo';
        $this->assertEquals('cn=foo,dc=acme,dc=org', $model->getCreatableDn());

        $model = new Entry();
        $this->assertEquals('cn=,dc=acme,dc=org', $model->getCreatableDn());

        $model = (new Entry())->inside('ou=Users,dc=acme,dc=org');
        $this->assertEquals('cn=,ou=Users,dc=acme,dc=org', $model->getCreatableDn());

        $model->cn = 'John Doe';
        $this->assertEquals('cn=John Doe,ou=Users,dc=acme,dc=org', $model->getCreatableDn());

        $model = (new Entry(['cn' => 'John Doe']))->inside((new Entry())->setDn('ou=Test,dc=acme,dc=org'));
        $this->assertEquals('cn=John Doe,ou=Test,dc=acme,dc=org', $model->getCreatableDn());
    }

    public function test_raw_attribute_filling_sets_dn()
    {
        $model = new Entry();

        $model->setRawAttributes(['dn' => 'bar']);
        $this->assertTrue($model->exists);
        $this->assertEquals('bar', $model->getDn());

        $model->setRawAttributes(['dn' => ['baz']]);
        $this->assertEquals('baz', $model->getDn());
        $this->assertEmpty($model->getAttributes());
    }

    public function test_raw_attribute_filling_sets_original()
    {
        $model = new Entry();
        $model->setRawAttributes(['foo' => 'bar']);
        $this->assertEquals(['foo' => 'bar'], $model->getOriginal());
    }

    public function test_raw_attribute_filling_removes_count_keys_recursively()
    {
        $model = new Entry();

        $model->setRawAttributes([
            'count' => 1,
            'foo' => [
                'count' => 1,
                'bar' => [
                    'count' => 1,
                    'baz' => [
                        'count' => 1,
                    ],
                ],
            ],
        ]);

        $this->assertEquals([
            'foo' => [
                'bar' => [
                    'baz' => [],
                ],
            ],
        ], $model->getAttributes());
    }

    public function test_attribute_manipulation()
    {
        $model = new Entry();
        $model->cn = 'foo';
        $this->assertEquals(['foo'], $model->cn);
        $this->assertTrue(isset($model->cn));
        unset($model->cn);
        $this->assertFalse(isset($model->cn));

        $model->setAttribute('bar', 1);
        $model->setFirstAttribute('baz', 2);
        $this->assertEquals([1], $model->getAttribute('bar'));
        $this->assertEquals([2], $model->getAttribute('baz'));
        $this->assertTrue(empty($model->invalid));
        $this->assertFalse(empty($model->bar));

        // Hyphenated attributes.
        $model->foo_bar = 'baz';
        $this->assertEquals(['baz'], $model->foo_bar);
        $this->assertEquals(['baz'], $model->getAttribute('foo-bar'));
    }

    public function test_has_attribute()
    {
        $model = new Entry();
        $this->assertFalse($model->hasAttribute('foo'));

        $model->foo = null;
        $this->assertFalse($model->hasAttribute('foo'));

        $model->foo = [];
        $this->assertFalse($model->hasAttribute('foo'));

        $model->foo = '';
        $this->assertTrue($model->hasAttribute('foo'));

        $model->foo = false;
        $this->assertTrue($model->hasAttribute('foo'));

        $model->foo = [''];
        $this->assertTrue($model->hasAttribute('foo'));

        $model->foo = ['bar', 'baz'];
        $this->assertTrue($model->hasAttribute('foo'));
    }

    public function test_setting_first_attribute()
    {
        $model = new Entry();
        $model->foo = ['bar', 'baz'];

        $model->setFirstAttribute('foo', 'zal');

        $this->assertEquals(['zal'], $model->foo);
        $this->assertEquals('zal', $model->getFirstAttribute('foo'));
    }

    public function test_adding_attribute_values()
    {
        $model = new Entry();
        $model->foo = ['bar', 'baz'];

        $model->addAttributeValue('foo', 'zal');
        $this->assertEquals(['bar', 'baz', 'zal'], $model->foo);

        $model->addAttributeValue('foo', 'bar');
        $this->assertEquals(['bar', 'baz', 'zal'], $model->foo);

        $this->assertEquals([
            (new BatchModification('foo', LDAP_MODIFY_BATCH_ADD, $model->foo))->get(),
        ], $model->getModifications());
    }

    public function test_removing_attribute_values()
    {
        $model = new Entry();
        $model->foo = ['bar', 'baz', 'zal'];

        $model->removeAttributeValue('missing', 'foo');
        $this->assertEquals([], $model->missing);

        $model->removeAttributeValue('foo', 'bar');
        $this->assertEquals(['baz', 'zal'], $model->foo);

        $model->removeAttributeValue('foo', 'invalid');
        $this->assertEquals(['baz', 'zal'], $model->foo);

        $model->removeAttributeValue('foo', ['baz', 'zal', 'zee']);
        $this->assertEquals([], $model->foo);

        $this->assertEmpty($model->getModifications());
    }

    public function test_attribute_keys_are_normalized()
    {
        $model = new Entry();
        $model->FOO = 1;
        $model->BARbAz = 2;
        $model->foo_bar = 3;
        $this->assertEquals([1], $model->foo);
        $this->assertEquals([1], $model->getAttribute('foo'));
        $this->assertEquals([2], $model->barbaz);
        $this->assertEquals([2], $model->getAttribute('barbaz'));
        $this->assertEquals([3], $model->foo_bar);
        $this->assertEquals([3], $model->getAttribute('foo-bar'));
    }

    public function test_dirty_attributes()
    {
        $model = new Entry(['foo' => 1, 'bar' => 2, 'baz' => 3]);
        $model->syncOriginal();
        $model->foo = 1;
        $model->bar = 20;
        $model->baz = 30;
        $model->other = 40;

        $this->assertFalse($model->isDirty('foo'));
        $this->assertTrue($model->isDirty('bar'));
        $this->assertTrue($model->isDirty('baz'));
        $this->assertTrue($model->isDirty('other'));
        $this->assertEquals([
            'bar' => [20],
            'baz' => [30],
            'other' => [40],
        ], $model->getDirty());
    }

    public function test_reset_integer_is_kept_in_tact_when_batch_modifications_are_generated()
    {
        $model = new Entry();

        $model->setRawAttributes(['pwdlastset' => 'value']);

        $model->pwdlastset = 0;

        $this->assertEquals([
            [
                'attrib' => 'pwdlastset',
                'modtype' => LDAP_MODIFY_BATCH_REPLACE,
                'values' => ['0'],
            ],
        ], $model->getModifications());
    }

    public function test_serialization()
    {
        $model = new Entry(['foo' => 'bar']);
        $this->assertEquals(['foo' => ['bar']], $model->jsonSerialize());

        $model->objectguid = 'bf9679e7-0de6-11d0-a285-00aa003049e2';
        $this->assertEquals([
            'foo' => ['bar'],
            'objectguid' => ['bf9679e7-0de6-11d0-a285-00aa003049e2'],
        ], $model->jsonSerialize());
        $this->assertEquals('{"foo":["bar"],"objectguid":["bf9679e7-0de6-11d0-a285-00aa003049e2"]}', json_encode($model->jsonSerialize()));
    }

    public function test_serialization_properly_converts_dates()
    {
        $date = Carbon::now();

        $model = new Entry([
            'createtimestamp' => (new Timestamp('ldap'))->fromDateTime($date),
        ]);

        $this->assertEquals(
            ['createtimestamp' => [$date->format(DateTimeInterface::ISO8601)]],
            $model->jsonSerialize()
        );

        $this->assertEquals(
            ['createtimestamp' => [$date->format('Y-m-d')]],
            $model->setDateFormat('Y-m-d')->jsonSerialize()
        );

        $this->assertEquals(
            ['createtimestamp' => [0]],
            (new Entry(['createtimestamp' => 0]))->jsonSerialize()
        );

        $this->assertEquals(
            ['createtimestamp' => ['0']],
            (new Entry(['createtimestamp' => '0']))->jsonSerialize()
        );
    }

    public function test_serialization_converts_all_timestamp_types()
    {
        $date = Carbon::now();

        $model = new ModelWithDatesStub([
            'standard' => $date,
            'windows' => $date,
            'windowsinteger' => $date,
        ]);

        $this->assertEquals([
            'standard' => [$date->format(DateTimeInterface::ISO8601)],
            'windows' => [$date->format(DateTimeInterface::ISO8601)],
            'windowsinteger' => [$date->format(DateTimeInterface::ISO8601)],
        ], $model->jsonSerialize());
    }

    public function test_serialization_of_all_date_types_ignores_reset_integer_zero()
    {
        $model = new ModelWithDatesStub([
            'standard' => 0,
            'windows' => 0,
            'windowsinteger' => 0,
        ]);

        $this->assertEquals([
            'standard' => [0],
            'windows' => [0],
            'windowsinteger' => [0],
        ], $model->jsonSerialize());
    }

    public function test_serialization_fails_when_converting_malformed_dates()
    {
        $this->expectException(\Exception::class);

        (new Entry(['createtimestamp' => 'invalid']))->jsonSerialize();
    }

    public function test_convert()
    {
        $model = new Entry(['foo' => 'bar']);
        $model->setDn('baz');
        $model->setConnection('other');

        $converted = $model->convert(new Entry());
        $this->assertEquals($model, $converted);
        $this->assertEquals('baz', $converted->getDn());
        $this->assertEquals('other', $converted->getConnectionName());

        $model = new Entry(['foo' => 'bar']);
        $model->setDn('foo');
        $model->setRawAttributes(['bar' => 'baz']);

        $converted = $model->convert(new Entry());
        $this->assertTrue($converted->exists);
        $this->assertEquals($model, $converted);
    }

    public function test_hydrate()
    {
        $records = [
            [
                'dn' => 'baz',
                'foo' => 'bar',
            ],
            [
                'dn' => 'foo',
                'bar' => 'baz',
            ],
        ];

        $model = new Entry();
        $model->setConnection('other');

        $collection = $model->hydrate($records);

        $this->assertTrue($collection->first()->exists);
        $this->assertEquals('baz', $collection->first()->getDn());
        $this->assertEquals('other', $collection->first()->getConnectionName());

        $this->assertTrue($collection->last()->exists);
        $this->assertEquals('foo', $collection->last()->getDn());
        $this->assertEquals('other', $collection->last()->getConnectionName());
    }

    public function test_add_modification()
    {
        $model = new Entry();
        $model->addModification(['attrib' => 'foo', 'values' => ['bar'], 'modtype' => 3]);
        $this->assertEquals([['attrib' => 'foo', 'values' => ['bar'], 'modtype' => 3]], $model->getModifications());

        $model = new Entry();
        $model->addModification(new BatchModification('foo', 3, ['bar']));
        $this->assertEquals([['attrib' => 'foo', 'values' => ['bar'], 'modtype' => 3]], $model->getModifications());
    }

    public function test_add_modification_without_attrib()
    {
        $model = new Entry();
        $this->expectException(\InvalidArgumentException::class);
        $model->addModification(['values' => ['Changed'], 'modtype' => 3]);
    }

    public function test_add_modification_without_modtype()
    {
        $model = new Entry();
        $this->expectException(\InvalidArgumentException::class);
        $model->addModification(['attrib' => 'foo', 'values' => ['bar']]);
    }

    public function test_add_modification_without_values()
    {
        $model = new Entry();
        $model->addModification(['attrib' => 'foo', 'modtype' => 3]);
        $this->assertEquals([['attrib' => 'foo', 'modtype' => 3]], $model->getModifications());
    }

    public function test_set_modifications()
    {
        $model = new Entry();
        $model->setModifications([
            ['attrib' => 'foo', 'modtype' => 3, 'values' => ['bar']],
            new BatchModification('bar', 3, ['baz']),
        ]);

        $this->assertEquals([
            ['attrib' => 'foo', 'modtype' => 3, 'values' => ['bar']],
            ['attrib' => 'bar', 'modtype' => 3, 'values' => ['baz']],
        ], $model->getModifications());
    }

    public function test_modifications_are_created_from_dirty()
    {
        $model = new Entry();
        $model->setRawAttributes([
            'cn' => ['Common Name'],
            'samaccountname' => ['Account Name'],
            'name' => ['Name'],
        ]);

        $model->cn = null;
        $model->samaccountname = 'Changed';
        $model->test = 'New Attribute';

        $modifications = $model->getModifications();

        // Removed 'cn' attribute
        $this->assertEquals('cn', $modifications[0]['attrib']);
        $this->assertFalse(isset($modifications[0]['values']));
        $this->assertEquals(18, $modifications[0]['modtype']);

        // Modified 'samaccountname' attribute
        $this->assertEquals('samaccountname', $modifications[1]['attrib']);
        $this->assertEquals(['Changed'], $modifications[1]['values']);
        $this->assertEquals(3, $modifications[1]['modtype']);

        // New 'test' attribute
        $this->assertEquals('test', $modifications[2]['attrib']);
        $this->assertEquals(['New Attribute'], $modifications[2]['values']);
        $this->assertEquals(1, $modifications[2]['modtype']);
    }

    public function test_modifications_that_contain_identical_values_are_not_used()
    {
        $model = new Entry();
        $model->setRawAttributes(['useraccountcontrol' => 512]);
        $model->useraccountcontrol = '512';

        $this->assertEmpty($model->getModifications());
    }

    public function test_modifications_are_not_stacked()
    {
        $model = (new Entry())->setRawAttributes([
            'cn' => ['Common Name'],
            'samaccountname' => ['Account Name'],
            'name' => ['Name'],
        ]);

        $model->cn = null;
        $model->samaccountname = 'Changed';
        $model->test = 'New Attribute';

        $this->assertCount(3, $model->getModifications());
        $this->assertCount(3, $model->getModifications());
    }

    public function test_is()
    {
        $model = (new Entry())->setDn('cn=john doe,dc=local,dc=com');

        $match = (new Entry())->setDn('cn=john doe,dc=local,dc=com');

        $notMatch = (new Entry())->setDn('cn=john doe,dc=local,dc=com')->setConnection('foo');

        $this->assertFalse($model->is());

        $this->assertFalse($model->is($notMatch));

        $this->assertTrue($model->is($match));
    }

    public function test_is_not()
    {
        $model = (new Entry())->setDn('cn=john doe,dc=local,dc=com');

        $match = (new Entry())->setDn('cn=john doe,dc=local,dc=com');

        $notMatch = (new Entry())->setDn('cn=john doe,dc=local,dc=com')->setConnection('foo');

        $this->assertTrue($model->isNot());

        $this->assertTrue($model->isNot($notMatch));

        $this->assertFalse($model->isNot($match));
    }

    public function test_is_descendent_of()
    {
        $model = new Entry();
        $this->assertFalse($model->isDescendantOf());
        $this->assertFalse($model->isDescendantOf(''));

        $model->setDn('cn=foo,ou=bar,dc=acme,dc=org');
        $this->assertFalse($model->isDescendantOf('foo'));
        $this->assertFalse($model->isDescendantOf('ou=bar'));
        $this->assertFalse($model->isDescendantOf('ou=bar,dc=acme'));
        $this->assertTrue($model->isDescendantOf('ou=bar,dc=acme,dc=org'));
        $this->assertTrue($model->isDescendantOf('ou=bar,dc=ACME,dc=org'));

        $parent = new Entry();
        $parent->setDn('ou=bar,dc=acme,dc=org');
        $this->assertTrue($model->isDescendantOf($parent));

        $parent->setDn('Ou=BaR,dc=acme,dc=org');
        $this->assertTrue($model->isDescendantOf($parent));
    }

    public function test_is_ancestor_of()
    {
        $model = new Entry();
        $this->assertFalse($model->isAncestorOf());
        $this->assertFalse($model->isAncestorOf(''));

        $model->setDn('ou=bar,dc=acme,dc=org');
        $this->assertTrue($model->isAncestorOf('cn=foo,ou=bar,dc=acme,dc=org'));
        $this->assertTrue($model->isAncestorOf('cn=foo,ou=test,ou=bar,dc=acme,dc=org'));

        $child = new Entry();
        $child->setDn('cn=foo,ou=bar,dc=acme,dc=org');
        $this->assertTrue($model->isAncestorOf($child));

        $child->setDn('cn=foo,Ou=BaR,dc=acme,dc=org');
        $this->assertTrue($model->isAncestorOf($child));
    }

    public function test_is_child_of()
    {
        $model = new Entry();
        $this->assertFalse($model->isChildOf());

        $model->setDn('dc=bar,dc=baz');
        $this->assertFalse($model->isChildOf('dc=bar,dc=baz'));
        $this->assertFalse($model->isChildOf('ou=foo,dc=bar,dc=baz'));

        $model->setDn('ou=foo,dc=bar,dc=baz');
        $this->assertFalse($model->isChildOf('dc=baz'));
        $this->assertTrue($model->isChildOf('dc=bar,dc=baz'));
        $this->assertTrue($model->isChildOf((new Entry())->setDn('dc=bar,dc=baz')));
    }

    public function test_is_parent_of()
    {
        $model = new Entry();
        $this->assertFalse($model->isParentOf());

        $model->setDn('dc=bar,dc=baz');
        $this->assertFalse($model->isParentOf('dc=bar,dc=baz'));

        $model->setDn('dc=baz');
        $this->assertFalse($model->isParentOf('ou=foo,dc=bar,dc=baz'));
        $this->assertTrue($model->isParentOf('dc=bar,dc=baz'));
        $this->assertTrue($model->isParentOf((new Entry())->setDn('dc=bar,dc=baz')));
    }

    public function test_create_fills_attributes()
    {
        $model = ModelCreateTestStub::create(['foo' => ['bar']]);

        $this->assertEquals(['foo' => ['bar']], $model->getAttributes());
    }

    public function test_generated_dns_are_properly_escaped()
    {
        $model = new Entry();

        $model->inside('dc=local,dc=com');

        $model->cn = "John\\,=+<>;\#Doe";

        $this->assertEquals('cn=John\5c\2c\3d\2b\3c\3e\3b\5c\23Doe,dc=local,dc=com', $model->getCreatableDn());
    }

    public function test_setting_dn_attributes_set_distinguished_name_on_model()
    {
        $this->assertEquals('foo', (new Entry(['distinguishedname' => 'foo']))->getDn());
        $this->assertEquals('foo', Entry::make(['distinguishedname' => 'foo'])->getDn());

        $model = new Entry();
        $model->dn = 'foo';
        $this->assertEquals('foo', $model->getDn());
        $this->assertEquals(['foo'], $model->getAttributes()['dn']);

        $model = new Entry();
        $model->distinguishedname = 'foo';
        $this->assertEquals('foo', $model->getDn());
        $this->assertEquals(['foo'], $model->getAttributes()['distinguishedname']);
    }
}

class ModelCreateTestStub extends Model
{
    public function save(array $attributes = []): void
    {
    }
}

class ModelWithDatesStub extends Model
{
    protected array $dates = [
        'standard' => 'ldap',
        'windows' => 'windows',
        'windowsinteger' => 'windows-int',
    ];
}

class ModelBootingTestStub extends Model
{
    public static function isBooted(): bool
    {
        return array_key_exists(static::class, static::booted());
    }

    public static function booted(): array
    {
        return static::$booted;
    }
}

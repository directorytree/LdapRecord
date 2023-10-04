<?php

namespace LdapRecord\Tests\Unit\Configuration;

use LdapRecord\Configuration\ConfigurationException;
use LdapRecord\Configuration\DomainConfiguration;
use LdapRecord\Tests\TestCase;

class DomainConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setup();

        DomainConfiguration::flushExtended();
    }

    public function test_getting_options()
    {
        $config = new DomainConfiguration();

        $this->assertEmpty($config->get('username'));
    }

    public function test_setting_options()
    {
        $config = new DomainConfiguration();

        $config->set('username', 'foo');

        $this->assertEquals('foo', $config->get('username'));
    }

    public function test_default_options()
    {
        $config = new DomainConfiguration();

        $this->assertEquals(389, $config->get('port'));
        $this->assertEmpty($config->get('hosts'));
        $this->assertEquals(0, $config->get('follow_referrals'));
        $this->assertEmpty($config->get('username'));
        $this->assertEmpty($config->get('password'));
        $this->assertEmpty($config->get('base_dn'));
        $this->assertFalse($config->get('use_ssl'));
        $this->assertFalse($config->get('use_tls'));
        $this->assertEmpty($config->get('options'));
    }

    public function test_all_options()
    {
        $config = new DomainConfiguration([
            'port' => 500,
            'base_dn' => 'dc=corp,dc=org',
            'hosts' => ['dc1', 'dc2'],
            'follow_referrals' => false,
            'username' => 'username',
            'password' => 'password',
            'use_ssl' => true,
            'use_tls' => false,
            'use_sasl' => true,
            'sasl_options' => [
                'mech' => 'GSSAPI',
            ],
            'options' => [
                LDAP_OPT_SIZELIMIT => 1000,
            ],
        ]);

        $this->assertEquals(500, $config->get('port'));
        $this->assertEquals('dc=corp,dc=org', $config->get('base_dn'));
        $this->assertEquals(['dc1', 'dc2'], $config->get('hosts'));
        $this->assertEquals('username', $config->get('username'));
        $this->assertEquals('password', $config->get('password'));
        $this->assertTrue($config->get('use_ssl'));
        $this->assertFalse($config->get('use_tls'));
        $this->assertTrue($config->get('use_sasl'));
        $this->assertEquals(['mech' => 'GSSAPI'], $config->get('sasl_options'));
        $this->assertEquals(
            [
                LDAP_OPT_SIZELIMIT => 1000,
            ],
            $config->get('options')
        );
    }

    public function test_get_all()
    {
        $config = new DomainConfiguration();

        $this->assertEquals([
            'hosts' => [],
            'timeout' => 5,
            'version' => 3,
            'port' => 389,
            'base_dn' => '',
            'username' => '',
            'password' => '',
            'use_ssl' => false,
            'use_tls' => false,
            'use_sasl' => false,
            'sasl_options' => [
                'mech' => null,
                'realm' => null,
                'authc_id' => null,
                'authz_id' => null,
                'props' => null,
            ],
            'follow_referrals' => false,
            'options' => [],
        ], $config->all());
    }

    public function test_port_can_be_numeric()
    {
        $this->assertEquals('123', (new DomainConfiguration(['port' => '123']))->get('port'));
    }

    public function test_extend()
    {
        DomainConfiguration::extend('name', '');

        $config = new DomainConfiguration(['name' => 'Domain 1']);

        $this->assertEquals('Domain 1', $config->get('name'));
    }

    public function test_extend_can_override_defaults()
    {
        DomainConfiguration::extend('port', 'default');

        $config = new DomainConfiguration(['port' => 'invalid']);

        $this->assertEquals('invalid', $config->get('port'));

        $this->expectException(ConfigurationException::class);

        $config->set('port', 123);
    }

    public function test_invalid_port()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['port' => 'invalid']);
    }

    public function test_invalid_base_dn()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['base_dn' => ['invalid']]);
    }

    public function test_invalid_domain_controllers()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['hosts' => 'invalid']);
    }

    public function test_invalid_admin_username()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['username' => ['invalid']]);
    }

    public function test_invalid_password()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['password' => ['invalid']]);
    }

    public function test_invalid_follow_referrals()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['follow_referrals' => 'invalid']);
    }

    public function test_invalid_use_ssl()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['use_ssl' => 'invalid']);
    }

    public function test_invalid_use_tls()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['use_tls' => 'invalid']);
    }

    public function test_invalid_options()
    {
        $this->expectException(ConfigurationException::class);

        new DomainConfiguration(['options' => 'invalid']);
    }

    public function test_options_can_be_overridden()
    {
        $config = new DomainConfiguration(['hosts' => ['one', 'two']]);

        $config->set('hosts', ['three', 'four']);

        $this->assertEquals(['three', 'four'], $config->get('hosts'));
    }
}

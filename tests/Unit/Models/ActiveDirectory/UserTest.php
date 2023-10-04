<?php

namespace LdapRecord\Tests\Unit\Models\ActiveDirectory;

use Carbon\Carbon;
use LdapRecord\Connection;
use LdapRecord\ConnectionException;
use LdapRecord\Container;
use LdapRecord\Models\ActiveDirectory\Scopes\RejectComputerObjectClass;
use LdapRecord\Models\ActiveDirectory\User;
use LdapRecord\Models\Attributes\AccountControl;
use LdapRecord\Models\Attributes\Password;
use LdapRecord\Models\Attributes\Timestamp;
use LdapRecord\Models\Entry;
use LdapRecord\Models\Model;
use LdapRecord\Testing\DirectoryFake;
use LdapRecord\Testing\LdapFake;
use LdapRecord\Tests\TestCase;

class UserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Container::addConnection(new Connection());
    }

    protected function tearDown(): void
    {
        Container::flush();

        parent::tearDown();
    }

    public function test_setting_password_requires_secure_connection()
    {
        $this->expectException(ConnectionException::class);

        new User(['password' => 'password']);
    }

    public function test_changing_password_requires_secure_connection()
    {
        $user = (new User)->setRawAttributes(['dn' => 'foo']);

        $this->expectException(ConnectionException::class);

        $user->password = ['old', 'new'];
    }

    public function test_set_password_behaves_identically_on_non_user_models()
    {
        DirectoryFake::setup()
            ->getLdapConnection()
            ->expect(LdapFake::operation('isUsingSSL')->andReturnTrue());

        $user = new User();

        $user->unicodepwd = 'foo';

        $nonUser = new Entry();

        $nonUser->unicodepwd = Password::encode('foo');

        $this->assertEquals($user->getModifications(), $nonUser->getModifications());
    }

    public function test_create_user_with_password()
    {
        DirectoryFake::setup()
            ->getLdapConnection()
            ->expect([
                LdapFake::operation('isUsingSSL')->once()->andReturnTrue(),
                LdapFake::operation('add')->once()->with(fn ($dn) => true, fn ($attributes) => (
                    $attributes['unicodepwd'] === [Password::encode('foobar')]
                    && $attributes['useraccountcontrol'] = 512
                ))->andReturnTrue(),
            ]);

        $user = new User();

        $user->password = 'foobar';
        $user->userAccountControl = 512;

        $user->save();
    }

    public function test_update_user_with_password()
    {
        DirectoryFake::setup()
            ->getLdapConnection()
            ->expect([
                LdapFake::operation('isUsingSSL')->once()->andReturnTrue(),
                LdapFake::operation('modifyBatch')->once()->with(
                    function ($dn) {
                        return $dn === 'cn=john,dc=local,dc=com';
                    },
                    function ($mods) {
                        return count($mods) === 2
                            && $mods[0] === [
                                'attrib' => 'unicodepwd',
                                'modtype' => LDAP_MODIFY_BATCH_REMOVE,
                                'values' => [Password::encode('old')],
                            ]
                            && $mods[1] === [
                                'attrib' => 'unicodepwd',
                                'modtype' => LDAP_MODIFY_BATCH_ADD,
                                'values' => [Password::encode('new')],
                            ];
                    }
                )->andReturnTrue(),
            ]);

        $user = new User();

        $user->setRawAttributes([
            'useraccountcontrol' => [512],
            'dn' => ['cn=john,dc=local,dc=com'],
        ]);

        $user->password = ['old', 'new'];

        $user->save();
    }

    public function test_reject_computer_object_class_is_a_default_scope()
    {
        $this->assertInstanceOf(RejectComputerObjectClass::class, (new User())->getGlobalScopes()[RejectComputerObjectClass::class]);
    }

    public function test_scope_where_has_mailbox_is_applied()
    {
        $filters = User::whereHasMailbox()->filters;

        $this->assertEquals('msExchMailboxGuid', $filters['and'][4]['field']);
        $this->assertEquals('*', $filters['and'][4]['operator']);
    }

    public function test_scope_where_has_lockout_is_applied()
    {
        $filters = User::whereHasLockout()->filters;

        $this->assertEquals('lockoutTime', $filters['and'][4]['field']);
        $this->assertEquals('>=', $filters['and'][4]['operator']);
        $this->assertEquals('\31', $filters['and'][4]['value']);
    }

    public function test_is_locked_out()
    {
        $lockoutTime = (new Timestamp('windows-int'))->fromDateTime(
            Carbon::now()->subMinutes(10)
        );

        $user = (new User())->setRawAttributes(
            ['lockouttime' => [$lockoutTime]]
        );

        $this->assertTrue($user->isLockedOut('UTC', $lockoutDuration = 11));
        $this->assertFalse($user->isLockedOut('UTC', $lockoutDuration = 10));
    }

    public function test_is_locked_out_with_only_duration()
    {
        $lockoutTime = (new Timestamp('windows-int'))->fromDateTime(
            Carbon::now()->subMinutes(10)
        );

        $user = (new User())->setRawAttributes(
            ['lockouttime' => [$lockoutTime]]
        );

        $this->assertTrue($user->isLockedOut($lockoutDuration = 11));
        $this->assertFalse($user->isLockedOut($lockoutDuration = 10));
    }

    public function test_user_with_no_account_control_returns_zero_value()
    {
        $this->assertEquals(0, (new User())->accountControl()->getValue());
    }

    public function test_user_with_account_control_returns_hydrated_account_control_instance()
    {
        $uac = (new User())->setRawAttribute('useraccountcontrol', '514')->accountControl();

        $this->assertSame(514, $uac->getValue());
        $this->assertTrue($uac->hasFlag(AccountControl::ACCOUNTDISABLE));
        $this->assertTrue($uac->hasFlag(AccountControl::NORMAL_ACCOUNT));
        $this->assertFalse($uac->hasFlag(AccountControl::DONT_EXPIRE_PASSWORD));
    }

    public function test_user_can_have_account_control_object_set_on_attribute()
    {
        $user = new User();

        $uac = $user->accountControl();

        $user->userAccountControl = $uac->setAccountIsNormal();

        $this->assertEquals(AccountControl::NORMAL_ACCOUNT, $user->accountControl()->getValue());
    }

    public function test_user_is_disabled()
    {
        $user = new User();

        $this->assertFalse($user->isDisabled());

        $user->setRawAttribute('useraccountcontrol', '514');

        $this->assertTrue($user->isDisabled());

        $user->setRawAttribute('useraccountcontrol', '512');

        $this->assertFalse($user->isDisabled());
    }

    public function test_user_is_enabled()
    {
        $user = new User();

        $this->assertTrue($user->isEnabled());

        $user->setRawAttribute('useraccountcontrol', '514');

        $this->assertFalse($user->isEnabled());

        $user->setRawAttribute('useraccountcontrol', '512');

        $this->assertTrue($user->isEnabled());
    }

    public function test_account_expires_with_maximum()
    {
        $user = new User();

        $max = Timestamp::WINDOWS_INT_MAX;

        $user->accountExpires = $max;

        $this->assertSame($max, $user->accountExpires);
    }

    public function test_account_expires_with_minimum()
    {
        $user = new User();

        $user->accountExpires = 0;

        $this->assertSame(0, $user->accountExpires);
    }
}

class UserPasswordTestStub extends User
{
    protected function assertSecureConnection(): void
    {
    }
}

class NonUserPasswordTestStub extends Model
{
}

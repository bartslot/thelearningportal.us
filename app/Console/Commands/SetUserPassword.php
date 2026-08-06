<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Support\Facades\Validator;

/**
 * Set a user's password from the server, typed in and never echoed.
 *
 * The web reset flow is the way an ordinary teacher recovers an account. This is the way an
 * administrator recovers one when email is not an option: a locked admin, a bounced address, a
 * pilot teacher on the phone. It also exists because the alternative people actually reach for is
 * pasting a Hash::make one-liner into tinker, which puts the password in shell history, in the
 * scrollback, and often in a chat log on the way there.
 *
 * The password is read with secret(), so it is not echoed and not stored in history. It is never
 * accepted as a command argument for the same reason.
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:password {email : The account to set a password for}';

    protected $description = "Set a user's password interactively (never echoed, never in shell history)";

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No account with the address {$email}.");

            return self::FAILURE;
        }

        $this->line("Account: <info>#{$user->id} {$user->email}</info> (role: {$user->role})");

        $password = (string) $this->secret('New password');
        if ($password === '') {
            $this->error('Nothing entered — no change made.');

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('Confirm password')) {
            $this->error('The two entries did not match — no change made.');

            return self::FAILURE;
        }

        // The same rule the web reset form applies, so a password set here cannot be weaker than one
        // a teacher could have chosen themselves.
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', PasswordRule::min(8)]],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $user->password = Hash::make($password);
        $user->save();

        $this->info("Password set for {$user->email}.");

        return self::SUCCESS;
    }
}

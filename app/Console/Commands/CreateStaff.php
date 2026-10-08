<?php

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Signature('staff:create
    {--name= : Full name}
    {--email= : Login email}
    {--password= : Password (asked for when missing)}
    {--reset : Set a new password for the active staff member with this email instead of creating one}')]
#[Description('Create a staff login, or reset one with --reset (works without a mail server)')]
class CreateStaff extends Command
{
    use PasswordValidationRules;

    public function handle(CreateNewUser $createNewUser): int
    {
        $email = (string) $this->option('email');
        $password = (string) ($this->option('password') ?? $this->secret('Password'));

        try {
            if ($this->option('reset')) {
                return $this->resetPassword($email, $password);
            }

            $staff = $createNewUser->create([
                'name' => (string) $this->option('name'),
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $this->info("Staff member {$staff->email} created.");

        return self::SUCCESS;
    }

    /**
     * Disabled staff are not reset here: they are restored on the Staff screen first.
     */
    private function resetPassword(string $email, string $password): int
    {
        $staff = User::query()->where('email', $email)->first();

        if ($staff === null) {
            $this->error("No active staff member uses {$email}. Restore them on the Staff screen first.");

            return self::FAILURE;
        }

        Validator::make(
            ['password' => $password, 'password_confirmation' => $password],
            ['password' => $this->passwordRules()],
        )->validate();

        $staff->update(['password' => $password]);

        $this->info("Password reset for {$staff->email}.");

        return self::SUCCESS;
    }
}

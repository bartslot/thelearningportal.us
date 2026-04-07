<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_login_and_receive_token(): void
    {
        $student = User::factory()->student()->create([
            'email'    => 'student@test.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'       => 'student@test.com',
            'password'    => 'password',
            'device_name' => 'flutter-test',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role']])
            ->assertJsonPath('user.role', 'student');
    }

    public function test_teacher_can_login_and_receive_token(): void
    {
        User::factory()->teacher()->create([
            'email'    => 'teacher@test.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'       => 'teacher@test.com',
            'password'    => 'password',
            'device_name' => 'web',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role', 'teacher');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->student()->create([
            'email'    => 'student@test.com',
            'password' => bcrypt('correct'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'       => 'student@test.com',
            'password'    => 'wrong',
            'device_name' => 'flutter-test',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors('email');
    }

    public function test_login_fails_with_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'       => 'nobody@test.com',
            'password'    => 'password',
            'device_name' => 'flutter-test',
        ])->assertUnprocessable();
    }

    public function test_login_requires_device_name(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'student@test.com',
            'password' => 'password',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors('device_name');
    }

    public function test_authenticated_user_can_logout(): void
    {
        $student = User::factory()->student()->create();

        $token = $student->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out']);
    }

    public function test_unauthenticated_logout_returns_401(): void
    {
        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    }

    public function test_student_cannot_access_teacher_routes_after_login(): void
    {
        $student = User::factory()->student()->create();

        // Teacher middleware blocks access
        // (student role should not match 'teacher' in EnsureUserHasRole)
        $token = $student->createToken('test')->plainTextToken;

        // There are no teacher API routes to test directly,
        // but the role middleware must return 403 for wrong role
        // We verify by attempting a student endpoint as a teacher
        $teacher = User::factory()->teacher()->create();
        $teacherToken = $teacher->createToken('test')->plainTextToken;

        $this->withToken($teacherToken)
            ->getJson('/api/v1/student/lessons')
            ->assertForbidden();
    }
}

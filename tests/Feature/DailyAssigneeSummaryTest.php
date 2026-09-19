<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\DailyAssignedTicketsSummary;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DailyAssigneeSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $email, bool $active = true, bool $verified = true): User
    {
        return User::create([
            'name' => 'Pessoa '.strstr($email, '@', true),
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
            'password' => 'SenhaTeste123',
            'active' => $active,
        ]);
    }

    private function ticket(User $assignee, string $statusKey, bool $trashed = false): Ticket
    {
        return Ticket::create([
            'number' => Ticket::nextNumber(),
            'origin' => 'internal',
            'title' => 'Ticket de teste '.$statusKey,
            'description' => 'Descrição',
            'priority' => 'normal',
            'status_id' => Status::system($statusKey)->id,
            'assignee_id' => $assignee->id,
            'trashed_at' => $trashed ? now() : null,
        ]);
    }

    public function test_daily_email_counts_only_active_assigned_tickets_and_is_sent_once_per_day(): void
    {
        Notification::fake();
        $recipient = $this->user('resumo@sutoorii.com');
        $inactive = $this->user('inativo@sutoorii.com', false);
        $unverified = $this->user('naoconfirmado@sutoorii.com', true, false);

        $first = $this->ticket($recipient, 'new');
        $second = $this->ticket($recipient, 'in_progress');
        $this->ticket($recipient, 'resolved');
        $this->ticket($recipient, 'closed');
        $this->ticket($recipient, 'cancelled');
        $this->ticket($recipient, 'new', true);
        $this->ticket($inactive, 'new');
        $this->ticket($unverified, 'new');

        $this->artisan('tickets:daily-assignee-summary')->assertSuccessful();
        $this->artisan('tickets:daily-assignee-summary')->assertSuccessful();

        Notification::assertSentToTimes($recipient, DailyAssignedTicketsSummary::class, 1);
        Notification::assertNotSentTo($inactive, DailyAssignedTicketsSummary::class);
        Notification::assertNotSentTo($unverified, DailyAssignedTicketsSummary::class);

        Notification::assertSentTo($recipient, DailyAssignedTicketsSummary::class, function ($notification) use ($recipient, $first, $second) {
            $mail = $notification->toMail($recipient);
            $this->assertSame(['mail'], $notification->via($recipient));
            $this->assertStringContainsString('2 tickets', $mail->subject);
            $this->assertContains('Você tem 2 tickets em aberto atribuídos a você.', $mail->introLines);
            $this->assertTrue(collect($mail->introLines)->contains(fn ($line) => str_contains((string) $line, '#'.$first->number)));
            $this->assertTrue(collect($mail->introLines)->contains(fn ($line) => str_contains((string) $line, '#'.$second->number)));
            $this->assertSame('Ver meus tickets', $mail->actionText);
            return true;
        });

        $this->assertSame(now(config('app.timezone'))->toDateString(), Setting::getValue('daily_assignee_summary.last_sent.'.$recipient->id));
    }

    public function test_daily_email_also_informs_users_who_have_zero_assigned_tickets(): void
    {
        Notification::fake();
        $recipient = $this->user('sem-tickets@sutoorii.com');

        $this->artisan('tickets:daily-assignee-summary')->assertSuccessful();

        Notification::assertSentTo($recipient, DailyAssignedTicketsSummary::class, function ($notification) use ($recipient) {
            $mail = $notification->toMail($recipient);
            $this->assertStringContainsString('0 tickets', $mail->subject);
            $this->assertContains('Você tem 0 tickets em aberto atribuídos a você.', $mail->introLines);
            return true;
        });
    }

    public function test_daily_summary_is_scheduled_at_eight_in_sao_paulo_and_uses_existing_scheduler(): void
    {
        $schedule = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString("tickets:daily-assignee-summary", $schedule);
        $this->assertStringContainsString("dailyAt('08:00')", $schedule);
        $this->assertStringContainsString("timezone('America/Sao_Paulo')", $schedule);
        $this->assertStringContainsString("tickets:maintenance", $schedule);
        $this->assertStringContainsString("tickets:process-recurrences", $schedule);
        $this->assertStringContainsString("queue:work", $schedule);
    }
}

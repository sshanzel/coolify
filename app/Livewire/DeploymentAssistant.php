<?php

namespace App\Livewire;

use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Validate;
use Livewire\Component;

class DeploymentAssistant extends Component
{
    use WithRateLimiting;

    public bool $expanded = false;

    #[Validate(['required', 'string', 'min:2', 'max:2000'])]
    public string $prompt = '';

    public ?string $threadId = null;

    public array $messages = [];

    public ?array $pendingProposal = null;

    public ?array $watch = null;

    public bool $watching = false;

    public function mount()
    {
        if (! $this->isAvailable()) {
            return;
        }
        $this->loadLatestThread();
    }

    public function getListeners(): array
    {
        $userId = auth()->id();
        if (! $userId) {
            return [];
        }

        return [
            "echo-private:user.{$userId},AssistantMessageReceived" => 'handleAssistantEvent',
        ];
    }

    public function handleAssistantEvent($event = null): void
    {
        if (! is_array($event) || data_get($event, 'threadId') !== $this->threadId) {
            return;
        }
        // Refresh display state only — never touch $prompt (it may hold
        // unsaved typing, see coolify#6062 for the convention).
        $this->refreshThread();
    }

    public function refreshThread(): void
    {
        if (blank($this->threadId) || ! $this->isAvailable()) {
            return;
        }
        try {
            $detail = $this->client()->timeout(10)
                ->get($this->shipbotUrl('/threads/'.$this->threadId), $this->identity())
                ->throw();
            $this->messages = $detail->json('messages', []);
            $this->pendingProposal = $detail->json('pending_proposal');
            $this->setWatch($detail->json('watch'));
        } catch (\Throwable) {
            // Polling/echo refreshes must fail silently — no error toasts.
        }
    }

    public function send()
    {
        try {
            $this->ensureAllowed();
            $this->validate();
            $this->rateLimit(10, 60);

            $response = $this->client()->timeout(120)->post($this->shipbotUrl('/chat'), [
                ...$this->identity(),
                'thread_id' => $this->threadId,
                'message' => $this->prompt,
            ]);
            $this->applyResponse($response);
            $this->reset('prompt');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function confirmProposal()
    {
        return $this->resolveProposal(approved: true);
    }

    public function cancelProposal()
    {
        return $this->resolveProposal(approved: false);
    }

    public function newConversation(): void
    {
        $this->reset('threadId', 'messages', 'pendingProposal', 'watch', 'watching');
    }

    public function render()
    {
        return view('livewire.deployment-assistant');
    }

    private function resolveProposal(bool $approved)
    {
        try {
            $this->ensureAllowed();
            if (blank($this->threadId) || blank($this->pendingProposal)) {
                $this->dispatch('error', 'There is no pending deployment proposal.');

                return;
            }

            $response = $this->client()->timeout(120)->post($this->shipbotUrl('/chat/confirm'), [
                ...$this->identity(),
                'thread_id' => $this->threadId,
                'approved' => $approved,
            ]);
            $this->applyResponse($response);
            if ($approved) {
                $this->dispatch('success', 'Deployment confirmed.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function loadLatestThread(): void
    {
        try {
            $threads = $this->client()->timeout(10)
                ->get($this->shipbotUrl('/threads'), $this->identity())
                ->throw()
                ->json('threads', []);
            if (blank($threads)) {
                return;
            }
            $detail = $this->client()->timeout(10)
                ->get($this->shipbotUrl('/threads/'.$threads[0]['thread_id']), $this->identity())
                ->throw();
            $this->threadId = $threads[0]['thread_id'];
            $this->messages = $detail->json('messages', []);
            $this->pendingProposal = $detail->json('pending_proposal');
            $this->setWatch($detail->json('watch'));
        } catch (\Throwable) {
            // Shipbot being unreachable must never break page loads — start empty.
        }
    }

    private function setWatch(?array $watch): void
    {
        $this->watch = $watch;
        $this->watching = (bool) data_get($watch, 'active');
    }

    private function isAvailable(): bool
    {
        return filled(config('services.shipbot.url')) && filled(config('services.shipbot.secret'));
    }

    private function ensureAllowed(): void
    {
        if (! $this->isAvailable()) {
            throw new \Exception('The deployment assistant is not configured.');
        }
    }

    private function applyResponse(Response $response): void
    {
        if (! $response->successful()) {
            throw new \Exception('Assistant error: '.$response->json('detail', 'the assistant is unavailable right now.'));
        }
        $this->threadId = $response->json('thread_id', $this->threadId);
        $this->messages = $response->json('messages', []);
        $this->pendingProposal = $response->json('pending_proposal');
        $this->setWatch($response->json('watch'));
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['X-Shipbot-Secret' => config('services.shipbot.secret')])->acceptJson();
    }

    private function shipbotUrl(string $path): string
    {
        return rtrim(config('services.shipbot.url'), '/').$path;
    }

    /**
     * @return array{team_id: int, user_id: int}
     */
    private function identity(): array
    {
        return [
            'team_id' => currentTeam()->id,
            'user_id' => auth()->id(),
        ];
    }
}

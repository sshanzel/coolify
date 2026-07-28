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

    public ?string $selectedOptionId = null;

    public ?array $watch = null;

    public bool $watching = false;

    public bool $running = false;

    public ?string $pendingUserMessage = null;

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
            $this->running = $detail->json('run_status') === 'running';
            $incoming = $detail->json('pending_proposal');
            if ($incoming !== $this->pendingProposal) {
                // A different card arrived — an in-flight pick belongs to the
                // old one. Same-card refreshes keep the user's pick.
                $this->selectedOptionId = null;
            }
            $this->pendingProposal = $incoming;
            $this->setWatch($detail->json('watch'));
            $this->reconcileOptimism();
        } catch (\Throwable) {
            // Polling/echo refreshes must fail silently — no error toasts.
        }
    }

    public function send()
    {
        $text = null;
        try {
            $this->ensureAllowed();
            $this->validate();
            $this->rateLimit(10, 60);

            // Optimistic: the user's bubble and a cleared composer render
            // immediately; the accept below is fast (the turn runs
            // server-side and streams back via Echo nudges + polling).
            $text = $this->prompt;
            $this->messages[] = ['role' => 'user', 'content' => $text];
            $this->running = true;
            $this->reset('prompt');

            $response = $this->client()->timeout(30)->post($this->shipbotUrl('/chat'), [
                ...$this->identity(),
                'thread_id' => $this->threadId,
                'message' => $text,
            ]);
            $this->applyResponse($response);
            // Guard later partial refreshes (pre-first-checkpoint) until the
            // server transcript contains the message.
            $this->pendingUserMessage = $text;
        } catch (\Throwable $e) {
            if ($text !== null) {
                // Roll the optimistic bubble back and restore the draft.
                array_pop($this->messages);
                $this->pendingUserMessage = null;
                $this->running = false;
                $this->prompt = $text;
            }

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

    public function submitSelection()
    {
        try {
            $this->ensureAllowed();
            if (blank($this->threadId) || data_get($this->pendingProposal, 'kind') !== 'selection_proposal') {
                $this->dispatch('error', 'There is no pending selection.');

                return;
            }
            // wire:click arguments come from the browser — only ids the card
            // actually offered may reach Shipbot.
            $offered = collect(data_get($this->pendingProposal, 'options', []))->pluck('id')->filter()->all();
            if (! in_array($this->selectedOptionId, $offered, true)) {
                $this->dispatch('error', 'Pick one of the offered options first.');

                return;
            }

            return $this->resolveProposal(approved: true, selectedOptionId: $this->selectedOptionId);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function newConversation(): void
    {
        $this->reset(
            'threadId', 'messages', 'pendingProposal', 'selectedOptionId',
            'watch', 'watching', 'running', 'pendingUserMessage',
        );
    }

    public function render()
    {
        return view('livewire.deployment-assistant');
    }

    private function resolveProposal(bool $approved, ?string $selectedOptionId = null)
    {
        try {
            $this->ensureAllowed();
            if (blank($this->threadId) || blank($this->pendingProposal)) {
                $this->dispatch('error', 'There is no pending deployment proposal.');

                return;
            }

            $payload = [
                ...$this->identity(),
                'thread_id' => $this->threadId,
                'approved' => $approved,
            ];
            if (filled($selectedOptionId)) {
                $payload['selected_option_id'] = $selectedOptionId;
            }
            $response = $this->client()->timeout(120)->post($this->shipbotUrl('/chat/confirm'), $payload);
            $this->applyResponse($response);
            if ($approved) {
                $this->dispatch('success', $selectedOptionId === null ? 'Deployment confirmed.' : 'Selection submitted.');
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
            $this->running = $detail->json('run_status') === 'running';
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
        $this->running = $response->json('run_status') === 'running';
        $this->pendingProposal = $response->json('pending_proposal');
        // A new (or cleared) card must never inherit the previous pick.
        $this->selectedOptionId = null;
        $this->setWatch($response->json('watch'));
        $this->reconcileOptimism();
    }

    private function reconcileOptimism(): void
    {
        if ($this->pendingUserMessage === null) {
            return;
        }
        $contained = collect($this->messages)->contains(
            fn ($message) => data_get($message, 'role') === 'user'
                && data_get($message, 'content') === $this->pendingUserMessage
        );
        if ($contained || ! $this->running) {
            // Checkpointed (or the run ended) — the transcript is authoritative.
            $this->pendingUserMessage = null;

            return;
        }
        // Pre-first-checkpoint race: the server transcript doesn't hold the
        // message yet — keep the optimistic bubble visible.
        $this->messages[] = ['role' => 'user', 'content' => $this->pendingUserMessage];
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

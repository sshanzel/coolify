<div x-data="{ expanded: @entangle('expanded') }" class="fixed bottom-0 right-0 z-60 mb-16 mr-4">
    <!-- Launcher -->
    <button @click="expanded = !expanded"
        class="flex items-center gap-2 px-4 py-2 rounded-lg shadow-lg transition-all duration-200 dark:bg-coolgray-100 bg-white dark:border dark:border-coolgray-200 hover:shadow-xl">
        <svg class="w-4 h-4 text-coollabs dark:text-warning" xmlns="http://www.w3.org/2000/svg" fill="none"
            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
        </svg>
        <span class="text-sm font-medium dark:text-neutral-200 text-gray-800">Assistant</span>
        <svg class="w-4 h-4 transition-transform duration-200 dark:text-neutral-400 text-gray-600"
            :class="{ 'rotate-180': expanded }" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
            stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <!-- Chat panel -->
    <div x-show="expanded" x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-2" x-cloak
        class="absolute bottom-full right-0 mb-2 w-96 rounded-lg shadow-xl dark:bg-coolgray-100 bg-white dark:border dark:border-coolgray-200">
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-2 border-b border-neutral-200 dark:border-coolgray-200">
            <span class="text-sm font-medium dark:text-neutral-200 text-gray-800">Deployment Assistant</span>
            <div class="flex items-center gap-3">
                <button wire:click="newConversation"
                    class="text-xs dark:text-neutral-400 text-gray-500 dark:hover:text-white hover:text-black">New
                    chat</button>
                <button @click="expanded = false"
                    class="dark:text-neutral-400 text-gray-500 dark:hover:text-white hover:text-black">
                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div x-data="{
            autoScrollEnabled: true,
            observer: null,
            scrollToBottom() {
                if (this.autoScrollEnabled) {
                    this.$el.scrollTop = this.$el.scrollHeight;
                }
            },
            isAtBottom() {
                const threshold = 5;
                return this.$el.scrollTop + this.$el.clientHeight >= this.$el.scrollHeight - threshold;
            },
            handleScroll() {
                this.autoScrollEnabled = this.isAtBottom();
            }
        }" x-init="$nextTick(() => scrollToBottom());
        $el.addEventListener('scroll', () => handleScroll());
        observer = new MutationObserver(() => {
            $nextTick(() => scrollToBottom());
        });
        observer.observe($el, {
            childList: true,
            subtree: true,
            characterData: true
        });" x-destroy="observer && observer.disconnect()"
            class="flex flex-col gap-2 px-4 py-3 h-80 overflow-y-auto scrollbar">
            @forelse ($messages as $message)
                @if (($message['role'] ?? '') === 'user')
                    <div
                        class="self-end max-w-[85%] px-3 py-2 rounded-lg text-sm dark:bg-coolgray-300 bg-neutral-100 dark:text-white text-gray-900">
                        {{ $message['content'] }}</div>
                @else
                    <div
                        class="self-start max-w-[85%] px-3 py-2 rounded-lg text-sm border border-neutral-200 dark:border-coolgray-200 dark:bg-coolgray-200 bg-white">
                        <div class="prose prose-sm dark:prose-invert max-w-none">
                            {!! Str::markdown($message['content'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    </div>
                @endif
            @empty
                <div class="py-8 text-sm text-center dark:text-neutral-500 text-gray-500">
                    Ask me to deploy a public git repository, e.g.<br>
                    <span class="font-mono text-xs">deploy https://github.com/org/repo</span>
                </div>
            @endforelse
            @if ($running)
                <div class="self-start px-3 py-2 text-sm dark:text-neutral-400 text-gray-500">Thinking...</div>
            @else
                <div wire:loading wire:target="confirmProposal, cancelProposal, submitSelection"
                    class="self-start px-3 py-2 text-sm dark:text-neutral-400 text-gray-500">Thinking...</div>
            @endif
        </div>

        <!-- Live progress (poll fallback while Echo pushes are the primary path):
             active while a chat turn runs and while a deployment is watched -->
        @if ($watching || $running)
            <div wire:poll.5000ms="refreshThread"
                class="flex items-center gap-2 px-4 py-2 text-xs border-t border-neutral-200 dark:border-coolgray-200 dark:text-neutral-300 text-gray-600">
                <svg class="w-3 h-3 text-coollabs dark:text-warning animate-spin" xmlns="http://www.w3.org/2000/svg"
                    fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
                @if ($watching)
                    Deployment in progress — {{ data_get($watch, 'milestone', 'queued') }}
                @else
                    Assistant is working…
                @endif
            </div>
        @endif

        <!-- Pending proposal -->
        @if (filled($pendingProposal))
            <div class="px-4 py-3 border-t border-neutral-200 dark:border-coolgray-200">
                @if (data_get($pendingProposal, 'kind') === 'deployment_proposal')
                    <div class="mb-2 text-xs font-medium uppercase dark:text-neutral-400 text-gray-500">
                        Deployment proposal
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs dark:text-neutral-300 text-gray-700">
                        <dt class="font-medium">Repository</dt>
                        <dd class="truncate font-mono">{{ data_get($pendingProposal, 'repo_url') }}</dd>
                        <dt class="font-medium">Branch</dt>
                        <dd class="font-mono">{{ data_get($pendingProposal, 'branch') }}</dd>
                        <dt class="font-medium">Server</dt>
                        <dd>{{ data_get($pendingProposal, 'server_name') }}</dd>
                        <dt class="font-medium">Project</dt>
                        <dd>{{ data_get($pendingProposal, 'project_name') }} /
                            {{ data_get($pendingProposal, 'environment_name') }}</dd>
                        <dt class="font-medium">Build pack</dt>
                        <dd>{{ data_get($pendingProposal, 'build_pack') }} (port
                            {{ data_get($pendingProposal, 'port') }})</dd>
                        @if (filled(data_get($pendingProposal, 'docker_compose_location')))
                            <dt class="font-medium">Compose file</dt>
                            <dd class="font-mono">{{ data_get($pendingProposal, 'docker_compose_location') }}</dd>
                        @endif
                        @if (filled(data_get($pendingProposal, 'dockerfile_location')))
                            <dt class="font-medium">Dockerfile</dt>
                            <dd class="font-mono">{{ data_get($pendingProposal, 'dockerfile_location') }}</dd>
                        @endif
                        @if (filled(data_get($pendingProposal, 'base_directory')))
                            <dt class="font-medium">Base directory</dt>
                            <dd class="font-mono">{{ data_get($pendingProposal, 'base_directory') }}</dd>
                        @endif
                    </dl>
                @elseif (data_get($pendingProposal, 'kind') === 'selection_proposal')
                    <div class="mb-2 text-xs font-medium uppercase dark:text-neutral-400 text-gray-500">
                        {{ data_get($pendingProposal, 'title', 'Choose an option') }}</div>
                    @if (filled(data_get($pendingProposal, 'reason')))
                        <p class="mb-2 text-xs dark:text-neutral-400 text-gray-600">
                            {{ data_get($pendingProposal, 'reason') }}</p>
                    @endif
                    <div class="flex flex-col gap-1">
                        @foreach (data_get($pendingProposal, 'options', []) as $option)
                            <label
                                class="flex items-start gap-2 px-2 py-1.5 rounded cursor-pointer text-xs dark:text-neutral-300 text-gray-700 dark:hover:bg-coolgray-200 hover:bg-neutral-100">
                                <input type="radio" name="assistant-selection" wire:model="selectedOptionId"
                                    value="{{ data_get($option, 'id') }}" class="mt-0.5" />
                                <span>
                                    <span class="font-medium">{{ data_get($option, 'label') }}</span>
                                    @if (filled(data_get($option, 'detail')))
                                        <span class="block dark:text-neutral-500 text-gray-500">
                                            {{ data_get($option, 'detail') }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                @else
                    <div class="mb-2 text-xs font-medium uppercase dark:text-neutral-400 text-gray-500">
                        {{ data_get($pendingProposal, 'title', 'Proposal') }}</div>
                    @if (filled(data_get($pendingProposal, 'reason')))
                        <p class="mb-2 text-xs dark:text-neutral-400 text-gray-600">
                            {{ data_get($pendingProposal, 'reason') }}</p>
                    @endif
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs dark:text-neutral-300 text-gray-700">
                        @foreach (data_get($pendingProposal, 'summary', []) as $item)
                            <dt class="font-medium">{{ data_get($item, 'label') }}</dt>
                            <dd class="truncate font-mono">{{ data_get($item, 'value') }}</dd>
                        @endforeach
                    </dl>
                @endif
                @if (data_get($pendingProposal, 'kind') === 'selection_proposal')
                    <div class="flex gap-2 mt-3">
                        <x-forms.button isHighlighted wire:click="submitSelection" wire:target="submitSelection">
                            Select</x-forms.button>
                        <x-forms.button wire:click="cancelProposal" wire:target="cancelProposal">Cancel</x-forms.button>
                    </div>
                @else
                    <div class="flex gap-2 mt-3">
                        <x-forms.button isHighlighted wire:click="confirmProposal" wire:target="confirmProposal">
                            Confirm</x-forms.button>
                        <x-forms.button wire:click="cancelProposal" wire:target="cancelProposal">Cancel</x-forms.button>
                    </div>
                @endif
            </div>
        @endif

        <!-- Composer -->
        <form wire:submit="send" class="flex items-end gap-2 px-4 py-3 border-t border-neutral-200 dark:border-coolgray-200">
            <x-forms.input id="prompt" placeholder="deploy https://github.com/org/repo"></x-forms.input>
            <x-forms.button type="submit" wire:target="send">Send</x-forms.button>
        </form>
    </div>
</div>

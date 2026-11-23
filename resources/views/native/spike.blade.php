<column class="safe-area w-full h-full gap-4 bg-theme-background px-6 py-6">
    <column class="w-full items-center">
        <text class="text-xl font-bold text-theme-on-surface">AirPHP - Spike</text>
        <text class="text-sm text-theme-on-surface-variant">Pure PHP HTTP server (stream_select)</text>
    </column>

    <row class="w-full gap-3">
        <pressable
            ref="start-server"
            a11y-label="Start server"
            class="flex-1 items-center rounded-lg bg-theme-primary px-4 py-3"
            @press="startServer"
        >
            <text class="font-semibold text-theme-on-primary">Start server</text>
        </pressable>
        <pressable
            ref="stop-server"
            a11y-label="Stop server"
            class="flex-1 items-center rounded-lg border border-theme-outline px-4 py-3"
            @press="stopServer"
        >
            <text class="font-semibold text-theme-on-surface">Stop</text>
        </pressable>
    </row>

    <column class="w-full gap-1 rounded-lg bg-theme-surface p-4">
        <text class="text-sm text-theme-on-surface">Status: {{ $this->status }}</text>
        <row class="w-full items-center gap-2">
            <text class="flex-1 text-sm text-theme-on-surface">
                Address: {{ $this->showSensitive ? $this->address : 'http://•••.•••.•••.•••:••••' }}
            </text>
            <pressable
                ref="toggle-sensitive"
                a11y-label="{{ $this->showSensitive ? 'Hide sensitive data' : 'Show sensitive data' }}"
                class="rounded-lg border border-theme-outline px-2 py-1"
                @press="toggleSensitive"
            >
                <text class="text-xs text-theme-on-surface-variant">{{ $this->showSensitive ? 'hide' : 'show' }}</text>
            </pressable>
        </row>
        <text class="text-sm text-theme-on-surface-variant">Requests: {{ $this->requests }} · Received: {{ $this->bytesReceived }}</text>
        <text class="text-xs text-theme-on-surface-variant">Saving to: {{ $this->uploadPath }}</text>
        @if ($this->error !== '')
            <text class="text-sm text-theme-destructive">Error: {{ $this->error }}</text>
        @endif
    </column>

    <text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant">Log</text>
    <scroll-view class="w-full flex-1 rounded-lg border border-theme-outline bg-theme-surface-variant p-3">
        <text class="text-xs text-theme-on-surface">{{ $this->log }}</text>
    </scroll-view>
</column>

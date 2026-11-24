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

    <column class="w-full gap-2 rounded-lg border border-theme-outline p-3">
        <text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant">SQLite stress test</text>
        <row class="w-full gap-3">
            <native:outlined-text-input class="flex-1" placeholder="rows" native:model.debounce.300ms="testRows" ref="test-rows" />
            <native:outlined-text-input class="flex-1" placeholder="delay ms (0 = burst)" native:model.debounce.300ms="testSleepMs" ref="test-sleep" />
        </row>
        <row class="w-full gap-3">
            <pressable
                ref="sqlite-test"
                a11y-label="SQLite dual-lane test"
                class="flex-1 items-center rounded-lg border border-theme-outline px-4 py-3"
                @press="sqliteTest"
            >
                <text class="font-semibold text-theme-on-surface">Run test</text>
            </pressable>
            <pressable
                ref="clean-test"
                a11y-label="Clean test data"
                class="flex-1 items-center rounded-lg border border-theme-outline px-4 py-3"
                @press="cleanTestData"
            >
                <text class="font-semibold text-theme-on-surface">Clean test data</text>
            </pressable>
        </row>
    </column>

    <column class="w-full gap-2 rounded-lg bg-theme-surface p-4">
        <text class="text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant">Capability probes (pre-fase 1)</text>
        <row class="w-full gap-2">
            <pressable a11y-label="Probe storage" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probeStorage">
                <text class="text-xs font-semibold text-theme-on-surface">Storage</text>
            </pressable>
            <pressable a11y-label="Probe QR render" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probeQr">
                <text class="text-xs font-semibold text-theme-on-surface">QR</text>
            </pressable>
            <pressable a11y-label="Probe share sheet" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probeShare">
                <text class="text-xs font-semibold text-theme-on-surface">Share</text>
            </pressable>
        </row>
        <row class="w-full gap-2">
            <pressable a11y-label="Probe UDP send" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probeUdpSend">
                <text class="text-xs font-semibold text-theme-on-surface">UDP send</text>
            </pressable>
            <pressable a11y-label="Probe UDP listener" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probeUdpListen">
                <text class="text-xs font-semibold text-theme-on-surface">UDP listen</text>
            </pressable>
            <pressable a11y-label="Probe media picker" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probePicker">
                <text class="text-xs font-semibold text-theme-on-surface">Picker</text>
            </pressable>
            <pressable a11y-label="Probe clipboard" class="flex-1 items-center rounded-lg border border-theme-outline px-2 py-2" @press="probeClipboard">
                <text class="text-xs font-semibold text-theme-on-surface">Clipboard</text>
            </pressable>
        </row>
        @if ($this->qrPath !== '')
            <image :src="$this->qrPath" class="h-40 w-40 self-center" />
        @elseif (count($this->qrMatrix) > 0)
            <column class="self-center bg-white p-1">
                @foreach ($this->qrMatrix as $qrRow)
                    <row class="gap-0">
                        @foreach ($qrRow as $cell)
                            <column class="h-1.5 w-1.5 {{ $cell ? 'bg-black' : 'bg-white' }}"></column>
                        @endforeach
                    </row>
                @endforeach
            </column>
        @endif
    </column>

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

    <row class="w-full items-center gap-2">
        <text class="flex-1 text-xs font-semibold uppercase tracking-wide text-theme-on-surface-variant">Log</text>
        <pressable a11y-label="Copy log to clipboard" class="rounded-lg border border-theme-outline px-2 py-1" @press="copyLog">
            <text class="text-xs text-theme-on-surface-variant">copy</text>
        </pressable>
        <pressable a11y-label="Export log" class="rounded-lg border border-theme-outline px-2 py-1" @press="exportLog">
            <text class="text-xs text-theme-on-surface-variant">export</text>
        </pressable>
    </row>
    <scroll-view class="w-full flex-1 rounded-lg border border-theme-outline bg-theme-surface-variant p-3">
        <column class="w-full gap-1">
            @foreach (array_filter(explode("\n", $this->log)) as $line)
                <text class="text-xs text-theme-on-surface">{{ $line }}</text>
            @endforeach
        </column>
    </scroll-view>
</column>

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'workflowStatus',
        'state',
        'health',
        'heartbeat',
        'currentAgent',
        'currentActivity',
        'agentRuns',
        'tasks',
        'workflowDuration',
        'agentRuntime',
        'terminal',
        'eventCount',
        'pollStatus',
    ];

    static values = {
        url: String,
        interval: { type: Number, default: 3000 },
        initialState: String,
        initialStatus: String,
        initialHealth: String,
    };

    connect() {
        this.fetching = false;
        this.currentRuns = [];
        this.currentWorkflow = null;
        this.lastEventFingerprint = '';
        this.lastPollAt = null;
        this.initialActionFingerprint = this.actionFingerprint(
            this.initialStateValue,
            this.initialStatusValue,
            this.initialHealthValue,
        );

        this.pollTimer = window.setInterval(
            () => this.refresh(),
            Math.max(1500, this.intervalValue || 3000),
        );
        this.clockTimer = window.setInterval(() => this.tick(), 1000);

        this.formatStaticTimestamps();
        this.refresh();
        this.tick();
    }

    disconnect() {
        window.clearInterval(this.pollTimer);
        window.clearInterval(this.clockTimer);
    }

    async refresh() {
        if (this.fetching || document.hidden || !this.hasUrlValue) {
            return;
        }

        this.fetching = true;
        try {
            const response = await fetch(this.urlValue, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const payload = await response.json();
            const data = payload?.data || {};
            const workflow = data.workflow || {};
            const runs = Array.isArray(data.agent_runs) ? data.agent_runs : [];
            const tasks = Array.isArray(data.tasks) ? data.tasks : [];
            const timeline = Array.isArray(data.timeline) ? data.timeline : [];

            this.currentWorkflow = workflow;
            this.currentRuns = runs;
            this.lastPollAt = Date.now();

            const state = String(workflow.state || workflow.current_state || this.initialStateValue || 'UNKNOWN').toUpperCase();
            const status = String(workflow.status || workflow.workflow_status || this.initialStatusValue || 'UNKNOWN').toUpperCase();
            const health = this.resolveHealth(workflow, state, status);

            const displayStatus = !this.isTerminal(state, status) && health === 'STALLED' ? 'STALLED' : status;
            this.setText(this.workflowStatusTargets, this.localizeStatus(displayStatus));
            this.setText(this.stateTargets, this.localizeStatus(state));
            const healthLabel = this.localizeStatus(health);
            this.setText(this.healthTargets, ['STALE', 'STALLED'].includes(health) ? '⚠ ' + healthLabel : healthLabel);

            if (this.hasHeartbeatTarget) {
                const heartbeat = workflow.heartbeat_at || workflow.last_activity_at || '';
                this.heartbeatTarget.dataset.timestamp = heartbeat;
                this.heartbeatTarget.title = heartbeat ? this.formatTimestamp(heartbeat) : 'heartbeat n/a';
            }

            if (this.hasAgentRunsTarget) {
                this.agentRunsTarget.textContent = String(runs.length);
            }

            if (this.hasTasksTarget) {
                const completed = tasks.filter((task) => String(task?.status || '').toUpperCase() === 'COMPLETED').length;
                this.tasksTarget.textContent = completed + '/' + tasks.length;
            }

            this.renderCurrentActivity(runs, timeline);
            this.renderTimeline(timeline, Number(data.timeline_count || timeline.length));

            const fingerprint = this.actionFingerprint(state, status, health);
            if (fingerprint !== this.initialActionFingerprint) {
                window.location.reload();
                return;
            }

            if (this.isTerminal(state, status)) {
                window.clearInterval(this.pollTimer);
            }
        } catch (error) {
            if (this.hasPollStatusTarget) {
                this.pollStatusTarget.textContent = 'помилка оновлення · повторюю';
                this.pollStatusTarget.title = error instanceof Error ? error.message : String(error);
            }
        } finally {
            this.fetching = false;
            this.tick();
        }
    }

    tick() {
        document.querySelectorAll('[data-engineering-live-duration-start]').forEach((node) => {
            const startedAt = node.dataset.engineeringLiveDurationStart || '';
            const finishedAt = node.dataset.engineeringLiveDurationFinish || '';
            const seconds = this.durationSeconds(startedAt, finishedAt);
            if (seconds !== null) {
                node.textContent = this.formatDuration(seconds);
            }
        });

        if (this.hasAgentRuntimeTarget && this.currentRuns.length > 0) {
            const total = this.currentRuns.reduce((sum, run) => {
                const duration = this.durationSeconds(run?.started_at || '', run?.finished_at || '');
                return sum + (duration ?? 0);
            }, 0);
            this.agentRuntimeTarget.textContent = this.formatDuration(total);
        }

        if (this.hasHeartbeatTarget) {
            const timestamp = this.heartbeatTarget.dataset.timestamp || '';
            this.heartbeatTarget.textContent = 'heartbeat: ' + this.relativeTime(timestamp);
        }

        if (this.hasPollStatusTarget && this.lastPollAt !== null) {
            const age = Math.max(0, Math.floor((Date.now() - this.lastPollAt) / 1000));
            if (!this.pollStatusTarget.dataset.eventMessage) {
                this.pollStatusTarget.textContent = age < 2 ? 'перевірено щойно' : 'перевірено ' + age + ' с тому';
            }
        }

        this.renderCurrentActivity(this.currentRuns, null);
    }

    renderCurrentActivity(runs, timeline = null) {
        const running = runs.find((run) => String(run?.status || '').toUpperCase() === 'RUNNING');
        if (running) {
            if (this.hasCurrentAgentTarget) {
                this.currentAgentTarget.textContent = this.localizeRole(running.role || 'AGENT');
            }
            if (this.hasCurrentActivityTarget) {
                const duration = this.durationSeconds(running.started_at || '', '');
                this.currentActivityTarget.textContent =
                    (duration !== null ? this.formatDuration(duration) + ' · ' : '') + String(running.id || '');
            }
            return;
        }

        if (this.hasCurrentAgentTarget) {
            this.currentAgentTarget.textContent = '—';
        }

        if (!this.hasCurrentActivityTarget) {
            return;
        }

        const events = Array.isArray(timeline) ? timeline : [];
        const latest = events[0] || null;
        if (latest) {
            this.currentActivityTarget.dataset.lastEventTime = String(latest.time || '');
            this.currentActivityTarget.dataset.lastEventTitle = String(latest.title || 'подія');
        }

        const title = this.currentActivityTarget.dataset.lastEventTitle || '';
        const time = this.currentActivityTarget.dataset.lastEventTime || '';
        this.currentActivityTarget.textContent = title
            ? 'останнє: ' + title + ' · ' + this.relativeTime(time)
            : 'активних операцій немає';
    }

    renderTimeline(events, totalCount) {
        if (this.hasEventCountTarget) {
            this.eventCountTarget.textContent = String(totalCount);
        }

        if (!this.hasTerminalTarget) {
            return;
        }

        const latest = events[0] || null;
        const fingerprint = latest
            ? [latest.reference_id || '', latest.time || '', latest.type || '', latest.status || ''].join('|')
            : '';

        const changed = this.lastEventFingerprint !== '' && fingerprint !== this.lastEventFingerprint;
        this.lastEventFingerprint = fingerprint;

        this.terminalTarget.replaceChildren();
        if (events.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'engineering-terminal__muted';
            empty.textContent = 'Подій виконання ще немає.';
            this.terminalTarget.append(empty);
        } else {
            events.forEach((event) => {
                const row = document.createElement('div');
                row.className = 'engineering-terminal__row';

                const time = document.createElement('span');
                time.className = 'engineering-terminal__muted';
                time.textContent = this.formatTimestamp(event?.time || '');

                const type = document.createElement('strong');
                type.textContent = this.localizeEventType(event?.type || 'EVENT');

                const message = document.createElement('span');
                const parts = [
                    event?.title || '',
                    event?.status ? this.localizeStatus(event.status) : '',
                    event?.detail || '',
                ].filter((value) => String(value).trim() !== '');
                message.textContent = parts.join(' · ');

                row.append(time, type, message);
                this.terminalTarget.append(row);
            });
        }

        if (this.hasPollStatusTarget) {
            this.pollStatusTarget.dataset.eventMessage = changed ? '1' : '';
            this.pollStatusTarget.textContent = changed ? 'нова подія · щойно' : 'без нових подій · перевірено щойно';
            if (changed) {
                window.setTimeout(() => {
                    if (this.hasPollStatusTarget) {
                        delete this.pollStatusTarget.dataset.eventMessage;
                    }
                }, 1800);
            }
        }
    }

    resolveHealth(workflow, state, status) {
        if (this.isTerminal(state, status)) {
            return 'TERMINAL';
        }
        if (['HUMAN_DECISION_REQUIRED', 'BLOCKED', 'ESCALATED', 'READY_FOR_HUMAN_APPROVAL'].includes(state)) {
            return 'WAITING';
        }

        const persisted = String(workflow?.health_status || '').toUpperCase();
        if (['STALLED', 'WAITING', 'TERMINAL'].includes(persisted)) {
            return persisted;
        }

        const timestamp = workflow?.heartbeat_at || workflow?.last_activity_at || '';
        const then = this.parseTimestamp(timestamp);
        if (then === null) {
            return persisted || 'UNKNOWN';
        }

        const age = Math.max(0, Math.floor((Date.now() - then) / 1000));
        if (age >= 1800) return 'STALLED';
        if (age >= 600) return 'STALE';
        return 'HEALTHY';
    }

    isTerminal(state, status) {
        return ['DONE', 'CANCELLED', 'FAILED'].includes(state)
            || ['COMPLETED', 'CANCELLED', 'FAILED'].includes(status);
    }

    actionFingerprint(state, status, health) {
        return [String(state || ''), String(status || ''), String(health || '')].join('|').toUpperCase();
    }

    durationSeconds(startedAt, finishedAt) {
        const start = this.parseTimestamp(startedAt);
        if (start === null) return null;
        const end = finishedAt ? this.parseTimestamp(finishedAt) : Date.now();
        if (end === null) return null;
        return Math.max(0, Math.floor((end - start) / 1000));
    }

    formatDuration(seconds) {
        const value = Math.max(0, Math.floor(seconds));
        if (value < 60) return value + 'с';
        if (value < 3600) return Math.floor(value / 60) + 'хв ' + (value % 60) + 'с';
        const hours = Math.floor(value / 3600);
        const minutes = Math.floor((value % 3600) / 60);
        if (hours < 24) return hours + 'г ' + String(minutes).padStart(2, '0') + 'хв';
        return Math.floor(hours / 24) + 'д ' + (hours % 24) + 'г';
    }

    relativeTime(timestamp) {
        const then = this.parseTimestamp(timestamp);
        if (then === null) return 'немає даних';
        const age = Math.max(0, Math.floor((Date.now() - then) / 1000));
        if (age < 5) return 'щойно';
        if (age < 60) return age + ' с тому';
        if (age < 3600) return Math.floor(age / 60) + ' хв тому';
        if (age < 86400) {
            const hours = Math.floor(age / 3600);
            const minutes = Math.floor((age % 3600) / 60);
            return hours + ' г' + (minutes > 0 ? ' ' + minutes + ' хв' : '') + ' тому';
        }
        const days = Math.floor(age / 86400);
        const hours = Math.floor((age % 86400) / 3600);
        return days + ' д' + (hours > 0 ? ' ' + hours + ' г' : '') + ' тому';
    }

    parseTimestamp(timestamp) {
        const value = String(timestamp || '').trim();
        if (value === '') return null;

        // Engineering persistence stores MySQL DATETIME values in UTC without an offset.
        // Treat that legacy/runtime shape as UTC, then render it in the browser's local timezone.
        const mysqlUtc = value.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d+))?$/);
        const normalized = mysqlUtc
            ? mysqlUtc[1] + 'T' + mysqlUtc[2] + (mysqlUtc[3] ? '.' + mysqlUtc[3].slice(0, 3).padEnd(3, '0') : '') + 'Z'
            : value;
        const parsed = Date.parse(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    }

    formatTimestamp(timestamp) {
        const parsed = this.parseTimestamp(timestamp);
        if (parsed === null) return String(timestamp || '').replace(/\.\d+$/, '');
        const date = new Date(parsed);
        const pad = (value) => String(value).padStart(2, '0');
        return [
            pad(date.getDate()) + '.' + pad(date.getMonth() + 1) + '.' + date.getFullYear(),
            pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds()),
        ].join(' ');
    }

    formatStaticTimestamps() {
        this.element.querySelectorAll('[data-engineering-live-timestamp]').forEach((node) => {
            const timestamp = node.dataset.engineeringLiveTimestamp || '';
            node.textContent = this.formatTimestamp(timestamp);
            node.title = timestamp;
        });
    }

    localizeStatus(value) {
        const key = String(value || '').toUpperCase();
        const labels = {
            RUNNING: 'ВИКОНУЄТЬСЯ',
            HEALTHY: 'НОРМА',
            STALE: 'НЕАКТИВНИЙ',
            STALLED: 'ЗУПИНЕНО',
            WAITING: 'ОЧІКУЄ',
            TERMINAL: 'ЗАВЕРШЕНО',
            COMPLETED: 'ЗАВЕРШЕНО',
            CANCELLED: 'СКАСОВАНО',
            FAILED: 'ПОМИЛКА',
            ERROR: 'ПОМИЛКА',
            STARTED: 'РОЗПОЧАТО',
            APPROVED: 'СХВАЛЕНО',
            PASS: 'ПРОЙДЕНО',
            PASSED: 'ПРОЙДЕНО',
            SUCCESS: 'УСПІШНО',
            TIMED_OUT: 'ТАЙМАУТ',
            NOT_REACHED: 'ЩЕ НЕ РОЗПОЧАТО',
            ANALYSIS: 'АНАЛІЗ',
            QA_PLANNING: 'ПЛАН ПЕРЕВІРКИ',
            ARCHITECTURE_PENDING: 'АРХІТЕКТУРА',
            DEVELOPMENT_RUNNING: 'РОЗРОБКА',
            REVIEW_PENDING: 'РЕВ’Ю',
            QA_PENDING: 'QA',
            READY_FOR_HUMAN_APPROVAL: 'ОЧІКУЄ ПІДТВЕРДЖЕННЯ',
            HUMAN_DECISION_REQUIRED: 'ПОТРІБНЕ РІШЕННЯ',
            BLOCKED: 'ЗАБЛОКОВАНО',
            ESCALATED: 'ЕСКАЛАЦІЯ',
            DONE: 'ГОТОВО',
        };
        return labels[key] || String(value || '—');
    }

    localizeRole(value) {
        const key = String(value || '').toUpperCase();
        const labels = {
            ENGINEERING_MANAGER: 'Менеджер розробки',
            PRINCIPAL_ARCHITECT: 'Архітектор',
            ARCHITECT: 'Архітектор',
            DEVELOPER: 'Розробник',
            REVIEWER: 'Рев’юер',
            QA: 'QA',
            AGENT: 'Агент',
        };
        return labels[key] || String(value || 'Агент');
    }

    localizeEventType(value) {
        const key = String(value || '').toUpperCase();
        const labels = {
            REPOSITORY: 'РЕПОЗИТОРІЙ',
            WATCHDOG: 'КОНТРОЛЬ',
            RUNTIME: 'СИСТЕМА',
            AGENT: 'АГЕНТ',
            TOOL: 'ІНСТРУМЕНТ',
            WORKFLOW: 'ПРОЦЕС',
            LLM: 'LLM',
            EVENT: 'ПОДІЯ',
        };
        return labels[key] || key;
    }

    setText(targets, value) {
        targets.forEach((node) => {
            node.textContent = value;
        });
    }
}

import { Controller } from '@hotwired/stimulus';

const UI = {
    uk: {
        staleProcess:'процес неактивний', stalledProcess:'процес зупинено', completedProcess:'процес завершено',
        stopPrefix:'оновлення зупинено · ', heartbeatPrefix:'сигнал: ', noNewEvents:'без нових подій · перевірено щойно',
        newEvent:'нова подія · щойно', updateError:'помилка оновлення · повторюю', checkedNow:'перевірено щойно',
        checkedSecondsAgo:'перевірено {n} с тому', heartbeatNA:'heartbeat немає', noData:'немає даних', justNow:'щойно',
        secondsAgo:'{n} с тому', minutesAgo:'{n} хв тому', hoursAgo:'{n} г{minutes} тому', daysAgo:'{n} д{hours} тому',
        noActiveOperations:'Немає активних операцій', lastPrefix:'останнє: ', event:'подія',
        noExecutionEvents:'Подій виконання поки немає.', seconds:'с', minutes:'хв', hours:'г', days:'д',
        status:{RUNNING:'ВИКОНУЄТЬСЯ',HEALTHY:'НОРМА',STALE:'НЕАКТИВНИЙ',STALLED:'ЗУПИНЕНО',WAITING:'ОЧІКУЄ',TERMINAL:'ЗАВЕРШЕНО',COMPLETED:'ЗАВЕРШЕНО',CANCELLED:'СКАСОВАНО',FAILED:'ПОМИЛКА',ERROR:'ПОМИЛКА',STARTED:'РОЗПОЧАТО',APPROVED:'СХВАЛЕНО',PASS:'ПРОЙДЕНО',PASSED:'ПРОЙДЕНО',SUCCESS:'УСПІШНО',TIMED_OUT:'ЧАС ВИЙШОВ',NOT_REACHED:'ЩЕ НЕ РОЗПОЧАТО',ANALYSIS:'АНАЛІЗ',QA_PLANNING:'ПЛАН ПЕРЕВІРКИ',ARCHITECTURE_PENDING:'АРХІТЕКТУРА',DEVELOPMENT_RUNNING:'РОЗРОБКА',REVIEW_PENDING:'РЕВ’Ю',QA_PENDING:'QA',READY_FOR_HUMAN_APPROVAL:'ОЧІКУЄ ПІДТВЕРДЖЕННЯ',HUMAN_DECISION_REQUIRED:'ПОТРІБНЕ РІШЕННЯ',BLOCKED:'ЗАБЛОКОВАНО',ESCALATED:'ЕСКАЛОВАНО',DONE:'ГОТОВО',QUEUED:'У ЧЕРЗІ',NEW:'НОВЕ',UNKNOWN:'НЕВІДОМО'},
        role:{ENGINEERING_MANAGER:'Менеджер / координатор',PRODUCT_REQUIREMENTS:'Продукт / вимоги',QA_PLANNER:'Планувальник QA',PRINCIPAL_ARCHITECT:'Головний архітектор',ARCHITECT:'Архітектор',DEVELOPER:'Розробник',REVIEWER:'Рев’юер',QA_EXECUTOR:'Виконавець QA',INTEGRATION_RELEASE:'Інтеграція / реліз',SECURITY_SPECIALIST:'Фахівець із безпеки',DATABASE_MIGRATION_SPECIALIST:'Фахівець із міграцій БД',PERFORMANCE_SPECIALIST:'Фахівець із продуктивності',DEVOPS_SPECIALIST:'Фахівець DevOps',DOCUMENTATION_SPECIALIST:'Фахівець із документації',API_SPECIALIST:'Фахівець з API',QA:'QA (застарілий)',AGENT:'Агент'},
        eventType:{REPOSITORY:'РЕПОЗИТОРІЙ',WATCHDOG:'КОНТРОЛЬ',RUNTIME:'СИСТЕМА',AGENT:'АГЕНТ',TOOL:'ІНСТРУМЕНТ',WORKFLOW:'ПРОЦЕС',LLM:'LLM',EVENT:'ПОДІЯ'}
    },
    en: {
        staleProcess:'process is stale', stalledProcess:'process is stalled', completedProcess:'workflow ended',
        stopPrefix:'Live updates stopped · ', heartbeatPrefix:'Signal: ', noNewEvents:'No new events · checked just now',
        newEvent:'New event · just now', updateError:'Update failed · retrying', checkedNow:'Checked just now',
        checkedSecondsAgo:'Checked {n}s ago', heartbeatNA:'No heartbeat', noData:'No data', justNow:'just now',
        secondsAgo:'{n}s ago', minutesAgo:'{n}m ago', hoursAgo:'{n}h{minutes} ago', daysAgo:'{n}d{hours} ago',
        noActiveOperations:'No active operations', lastPrefix:'Last: ', event:'event',
        noExecutionEvents:'No execution events yet.', seconds:'s', minutes:'m', hours:'h', days:'d',
        status:{RUNNING:'RUNNING',HEALTHY:'HEALTHY',STALE:'STALE',STALLED:'STALLED',WAITING:'WAITING',TERMINAL:'TERMINAL',COMPLETED:'COMPLETED',CANCELLED:'CANCELLED',FAILED:'FAILED',ERROR:'ERROR',STARTED:'STARTED',APPROVED:'APPROVED',PASS:'PASSED',PASSED:'PASSED',SUCCESS:'SUCCEEDED',TIMED_OUT:'TIMED OUT',NOT_REACHED:'NOT REACHED',ANALYSIS:'ANALYSIS',QA_PLANNING:'QA PLANNING',ARCHITECTURE_PENDING:'ARCHITECTURE',DEVELOPMENT_RUNNING:'DEVELOPMENT',REVIEW_PENDING:'REVIEW',QA_PENDING:'QA',READY_FOR_HUMAN_APPROVAL:'AWAITING HUMAN APPROVAL',HUMAN_DECISION_REQUIRED:'DECISION REQUIRED',BLOCKED:'BLOCKED',ESCALATED:'ESCALATED',DONE:'DONE',QUEUED:'QUEUED',NEW:'NEW',UNKNOWN:'UNKNOWN'},
        role:{ENGINEERING_MANAGER:'Manager / coordinator',PRODUCT_REQUIREMENTS:'Product / requirements',QA_PLANNER:'QA planner',PRINCIPAL_ARCHITECT:'Principal architect',ARCHITECT:'Principal architect',DEVELOPER:'Developer',REVIEWER:'Reviewer',QA_EXECUTOR:'QA executor',INTEGRATION_RELEASE:'Integration / release',SECURITY_SPECIALIST:'Security specialist',DATABASE_MIGRATION_SPECIALIST:'Database migration specialist',PERFORMANCE_SPECIALIST:'Performance specialist',DEVOPS_SPECIALIST:'DevOps specialist',DOCUMENTATION_SPECIALIST:'Documentation specialist',API_SPECIALIST:'API specialist',QA:'QA (legacy)',AGENT:'Agent'},
        eventType:{REPOSITORY:'REPOSITORY',WATCHDOG:'WATCHDOG',RUNTIME:'RUNTIME',AGENT:'AGENT',TOOL:'TOOL',WORKFLOW:'WORKFLOW',LLM:'LLM',EVENT:'EVENT'}
    }
};

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
        interval: { type: Number, default: 10000 },
        initialState: String,
        initialStatus: String,
        initialHealth: String,
    };

    connect() {
        this.fetching = false;
        this.liveUpdatesStopped = false;
        this.currentRuns = [];
        this.currentWorkflow = null;
        this.currentHealth = String(this.initialHealthValue || '').toUpperCase();
        this.currentStopAt = this.hasWorkflowDurationTarget
            ? (this.workflowDurationTarget.dataset.engineeringLiveDurationStop || '')
            : '';
        this.lastEventFingerprint = '';
        this.lastPollAt = null;
        this.initialActionFingerprint = this.actionFingerprint(
            this.initialStateValue,
            this.initialStatusValue,
            this.initialHealthValue,
        );

        this.pollTimer = null;
        this.clockTimer = null;

        this.formatStaticTimestamps();

        const initialState = String(this.initialStateValue || '').toUpperCase();
        const initialStatus = String(this.initialStatusValue || '').toUpperCase();
        if (this.shouldStopLiveUpdates(this.currentHealth, initialState, initialStatus)) {
            this.stopLiveUpdates(this.stopReason(this.currentHealth, initialState, initialStatus));
            this.tick();
            return;
        }

        this.pollTimer = window.setInterval(
            () => this.refresh(),
            Math.max(3000, this.intervalValue || 10000),
        );
        this.clockTimer = window.setInterval(() => this.tick(), 1000);

        this.refresh();
        this.tick();
    }

    disconnect() {
        this.stopLiveUpdates();
    }

    async refresh() {
        if (this.liveUpdatesStopped || this.fetching || document.hidden || !this.hasUrlValue) {
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
            this.currentHealth = health;
            this.currentStopAt = ['STALE', 'STALLED'].includes(health)
                ? String(workflow.stalled_at || workflow.heartbeat_at || workflow.last_activity_at || '')
                : '';
            if (this.hasWorkflowDurationTarget) {
                this.workflowDurationTarget.dataset.engineeringLiveDurationStop = this.currentStopAt;
            }

            const displayStatus = !this.isTerminal(state, status) && ['STALE', 'STALLED'].includes(health) ? health : status;
            this.setText(this.workflowStatusTargets, this.localizeStatus(displayStatus));
            this.setText(this.stateTargets, this.localizeStatus(state));
            const healthLabel = this.localizeStatus(health);
            this.setText(this.healthTargets, ['STALE', 'STALLED'].includes(health) ? '⚠ ' + healthLabel : healthLabel);

            if (this.hasHeartbeatTarget) {
                const heartbeat = workflow.heartbeat_at || workflow.last_activity_at || '';
                this.heartbeatTarget.dataset.timestamp = heartbeat;
                this.heartbeatTarget.title = heartbeat ? this.formatTimestamp(heartbeat) : this.ui().heartbeatNA;
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

            if (this.shouldStopLiveUpdates(health, state, status)) {
                this.stopLiveUpdates(this.stopReason(health, state, status));
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
            const stopAt = node.dataset.engineeringLiveDurationStop || '';
            const seconds = this.durationSeconds(startedAt, finishedAt || stopAt);
            if (seconds !== null) {
                node.textContent = this.formatDuration(seconds);
            }
        });

        if (this.hasAgentRuntimeTarget && this.currentRuns.length > 0) {
            const total = this.currentRuns.reduce((sum, run) => {
                const runEnd = run?.finished_at || (['STALE', 'STALLED'].includes(this.currentHealth) ? this.currentStopAt : '');
                const duration = this.durationSeconds(run?.started_at || '', runEnd || '');
                return sum + (duration ?? 0);
            }, 0);
            this.agentRuntimeTarget.textContent = this.formatDuration(total);
        }

        if (this.hasHeartbeatTarget) {
            const timestamp = this.heartbeatTarget.dataset.timestamp || '';
            this.heartbeatTarget.textContent = this.ui().heartbeatPrefix + this.relativeTime(timestamp);
        }

        if (!this.liveUpdatesStopped && this.hasPollStatusTarget && this.lastPollAt !== null) {
            const age = Math.max(0, Math.floor((Date.now() - this.lastPollAt) / 1000));
            if (!this.pollStatusTarget.dataset.eventMessage) {
                this.pollStatusTarget.textContent = age < 2 ? this.ui().checkedNow : this.ui().checkedSecondsAgo.replace('{n}', String(age));
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
                const runEnd = ['STALE', 'STALLED'].includes(this.currentHealth) ? this.currentStopAt : '';
                const duration = this.durationSeconds(running.started_at || '', runEnd);
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
            this.currentActivityTarget.dataset.lastEventTitle = String(latest.title || this.ui().event);
        }

        const title = this.currentActivityTarget.dataset.lastEventTitle || '';
        const time = this.currentActivityTarget.dataset.lastEventTime || '';
        this.currentActivityTarget.textContent = title
            ? this.ui().lastPrefix + title + ' · ' + this.relativeTime(time)
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
            let previousGroup = null;
            events.forEach((event) => {
                const groupKey = String(event?.group_key || 'system');
                if (groupKey !== previousGroup) {
                    const heading = document.createElement('div');
                    heading.className = 'engineering-terminal__group';
                    heading.dataset.engineeringGroup = groupKey;
                    heading.textContent = String(event?.group_label || 'Системні та неатрибутовані події');
                    this.terminalTarget.append(heading);
                    previousGroup = groupKey;
                }
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
                if (event?.evidence_round !== null && event?.evidence_round !== undefined) {
                    const badge = document.createElement('span');
                    badge.className = 'engineering-terminal__round';
                    badge.textContent = 'Evidence #' + String(event.evidence_round);
                    message.append(' ', badge);
                }

                row.append(time, type, message);
                this.terminalTarget.append(row);
            });
        }

        if (this.hasPollStatusTarget) {
            this.pollStatusTarget.dataset.eventMessage = changed ? '1' : '';
            this.pollStatusTarget.textContent = changed ? this.ui().newEvent : this.ui().noNewEvents;
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
        if (['STALE', 'STALLED', 'WAITING', 'TERMINAL'].includes(persisted)) {
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

    shouldStopLiveUpdates(health, state, status) {
        // Runtime health may recover while the workflow remains nonterminal.
        // Keep polling after STALE/STALLED; only terminal workflow states end polling.
        return this.isTerminal(state, status);
    }

    stopReason(health, state, status) {
        const normalized = String(health || '').toUpperCase();
        if (normalized === 'STALE') return this.ui().staleProcess;
        if (normalized === 'STALLED') return this.ui().stalledProcess;
        if (this.isTerminal(state, status)) return this.ui().completedProcess;
        return '';
    }

    stopLiveUpdates(message = '') {
        this.liveUpdatesStopped = true;
        if (this.pollTimer !== null) {
            window.clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        if (this.clockTimer !== null) {
            window.clearInterval(this.clockTimer);
            this.clockTimer = null;
        }
        if (message && this.hasPollStatusTarget) {
            delete this.pollStatusTarget.dataset.eventMessage;
            this.pollStatusTarget.textContent = this.ui().stopPrefix + message;
            this.pollStatusTarget.title = '';
        }
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
        if (value < 60) return value + this.ui().seconds;
        if (value < 3600) return Math.floor(value / 60) + this.ui().minutes + ' ' + (value % 60) + this.ui().seconds;
        const hours = Math.floor(value / 3600);
        const minutes = Math.floor((value % 3600) / 60);
        if (hours < 24) return hours + this.ui().hours + ' ' + String(minutes).padStart(2, '0') + this.ui().minutes;
        return Math.floor(hours / 24) + this.ui().days + ' ' + (hours % 24) + this.ui().hours;
    }

    relativeTime(timestamp) {
        const then = this.parseTimestamp(timestamp);
        if (then === null) return this.ui().noData;
        const age = Math.max(0, Math.floor((Date.now() - then) / 1000));
        if (age < 5) return this.ui().justNow;
        if (age < 60) return this.ui().secondsAgo.replace('{n}', String(age));
        if (age < 3600) return this.ui().minutesAgo.replace('{n}', String(Math.floor(age / 60)));
        if (age < 86400) {
            const hours = Math.floor(age / 3600);
            const minutes = Math.floor((age % 3600) / 60);
            return this.ui().hoursAgo.replace('{n}', String(hours)).replace('{minutes}', minutes > 0 ? ' ' + minutes + ' ' + this.ui().minutes : '') + (this.locale() === 'en' ? '' : ' тому');
        }
        const days = Math.floor(age / 86400);
        const hours = Math.floor((age % 86400) / 3600);
        return this.ui().daysAgo.replace('{n}', String(days)).replace('{hours}', hours > 0 ? ' ' + hours + ' ' + this.ui().hours : '') + (this.locale() === 'en' ? '' : ' тому');
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
        return new Intl.DateTimeFormat(this.locale() === 'en' ? 'en-GB' : 'uk-UA', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        }).format(date);
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
        return this.ui().status[key] || String(value || '—');
    }

    localizeRole(value) {
        const key = String(value || '').toUpperCase();
        return this.ui().role[key] || String(value || this.ui().role.AGENT);
    }

    localizeEventType(value) {
        const key = String(value || '').toUpperCase();
        return this.ui().eventType[key] || key;
    }

    locale() {
        return String(document.documentElement.lang || 'uk').toLowerCase().startsWith('en') ? 'en' : 'uk';
    }

    ui() {
        return UI[this.locale()];
    }

    setText(targets, value) {
        targets.forEach((node) => {
            node.textContent = value;
        });
    }
}

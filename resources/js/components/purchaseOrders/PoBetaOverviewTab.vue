<script setup lang="ts">
import { computed, ref } from 'vue';

import { formatPoActivityTimestamp } from '../../lib/datetime';
import {
    formatCadDisplay,
    formatCountDisplay,
    poCurrentStepSummary,
    poRecentActivity,
    poReceivingBar,
    poReceivingTrackState,
    poShipmentLabel,
    poWorkflowTimelineFrame,
    type PoBetaAttentionItem,
    type PoBetaSource,
    type PoBetaTab,
    type PoReceivingTotals,
    type PoWorkflowProgress,
} from '../../lib/purchaseOrderBeta';
import type { PoSetPricePreview } from './PoWorkflowSetPricesDialog.vue';
import type { PoSellingPriceHistoryEntry } from './PoSellingPriceHistoryPanel.vue';

const props = defineProps<{
    po: PoBetaSource;
    progress: PoWorkflowProgress;
    receiving: PoReceivingTotals | null;
    attention: PoBetaAttentionItem[];
    extras: number | null;
    shippingAndSurcharge: number | null;
    history: PoSellingPriceHistoryEntry[];
    pricePreview: PoSetPricePreview | null;
    tabHref: (tab: PoBetaTab) => string;
}>();

const emit = defineEmits<{
    (e: 'continue'): void;
}>();

const showCalculation = ref(false);
const detailsOpen = ref(false);
const notesOpen = ref(false);
const activityOpen = ref(true);

const bar = computed(() => (props.receiving ? poReceivingBar(props.receiving) : null));
const track = computed(() => (props.receiving ? poReceivingTrackState(props.receiving) : null));
const timeline = computed(() => poWorkflowTimelineFrame(props.progress));
const isWorkflowComplete = computed(
    () => props.progress.completed === props.progress.total,
);
const latestActivity = computed(() => props.history[0] ?? null);
const activity = computed(() => poRecentActivity(props.po, props.receiving, latestActivity.value));
const pricingAttentionRows = computed(
    () => props.attention.filter((item) => item.id === 'landed' || item.id === 'barcodes').length,
);
const receivingAttentionRows = computed(
    () => props.attention.filter((item) => item.id === 'receiving').length,
);
const currentSummary = computed(() =>
    poCurrentStepSummary(props.po, props.progress, props.pricePreview),
);
const earlierActivityCount = computed(() => Math.max(0, props.history.length - 1));
const notesPreview = computed(() => {
    const notes = props.po.notes?.trim();
    if (!notes) return 'No notes';
    return notes.length > 28 ? `${notes.slice(0, 28)}…` : notes;
});
</script>

<template>
    <div class="po-beta__grid">
        <div class="po-beta__main">
            <section class="po-beta__section" data-testid="po-beta-timeline">
                <p class="po-beta__label">Current step</p>
                <div class="po-beta__timeline">
                    <div v-if="timeline.previous" class="po-beta__timeline-item is-done">
                        <span class="po-beta__timeline-mark" aria-hidden="true">✓</span>
                        <div class="po-beta__timeline-copy po-beta__quiet">
                            {{ timeline.previous.num }} · {{ timeline.previous.label }}
                        </div>
                    </div>
                    <div
                        class="po-beta__timeline-item"
                        :class="isWorkflowComplete ? 'is-done' : 'is-current'"
                    >
                        <span class="po-beta__timeline-mark" aria-hidden="true">{{
                            isWorkflowComplete ? '✓' : ''
                        }}</span>
                        <div class="po-beta__timeline-copy" :class="{ 'po-beta__quiet': isWorkflowComplete }">
                            <div :class="isWorkflowComplete ? '' : 'po-beta__current'">
                                <div>
                                    <strong>
                                        {{ timeline.current.num }} · {{ timeline.current.label }}
                                    </strong>
                                    <p class="po-beta__quiet">{{ currentSummary }}</p>
                                </div>
                                <button
                                    v-if="progress.currentKey === 'set_selling_price'"
                                    type="button"
                                    class="po-beta__btn po-beta__btn--primary po-beta__btn--compact"
                                    data-testid="po-beta-continue-review"
                                    @click="emit('continue')"
                                >
                                    Continue review →
                                </button>
                            </div>
                        </div>
                    </div>
                    <div v-if="timeline.next" class="po-beta__timeline-item is-next">
                        <span class="po-beta__timeline-mark" aria-hidden="true"></span>
                        <div class="po-beta__timeline-copy po-beta__quiet">
                            {{ timeline.next.num }} · {{ timeline.next.label }}
                        </div>
                    </div>
                </div>
                <div class="po-beta__section-foot">
                    <a class="po-beta__link" :href="tabHref('workflow')"
                        >View full 11-step workflow →</a
                    >
                </div>
            </section>

            <section v-if="bar && track" class="po-beta__section" data-testid="po-beta-receiving-bar">
                <p class="po-beta__label">Receiving</p>
                <div class="po-beta__stepper">
                    <ol class="po-beta__stepper-points">
                        <li>
                            <span>Ordered</span>
                            <strong>{{ formatCountDisplay(bar.ordered) }}</strong>
                        </li>
                        <li>
                            <span>Shipped</span>
                            <strong>{{ formatCountDisplay(bar.shipped) }}</strong>
                        </li>
                        <li :class="{ 'is-alert': bar.unresolved > 0 }">
                            <span>Received</span>
                            <strong>{{ formatCountDisplay(bar.received) }}</strong>
                        </li>
                        <li>
                            <span>On shelves</span>
                            <strong>{{ formatCountDisplay(bar.onShelves) }}</strong>
                        </li>
                    </ol>
                    <div class="po-beta__stepper-track" aria-hidden="true">
                        <i class="po-beta__stepper-dot"></i>
                        <span class="po-beta__stepper-seg" :class="track.seg1"></span>
                        <i class="po-beta__stepper-dot"></i>
                        <span class="po-beta__stepper-seg" :class="track.seg2"></span>
                        <i
                            class="po-beta__stepper-dot"
                            :class="{ 'is-alert': track.receivedDotAlert }"
                        ></i>
                        <span class="po-beta__stepper-seg" :class="track.seg3"></span>
                        <i class="po-beta__stepper-dot"></i>
                    </div>
                    <div class="po-beta__stepper-notes">
                        <span></span>
                        <span></span>
                        <em v-if="bar.unresolved > 0"
                            >{{ formatCountDisplay(bar.unresolved) }} units unresolved</em
                        >
                        <span v-else></span>
                        <span></span>
                    </div>
                </div>
            </section>

            <section class="po-beta__section" data-testid="po-beta-attention">
                <p class="po-beta__label">Needs attention</p>
                <div v-if="attention.length === 0" class="po-beta__quiet">No blocking issues.</div>
                <div v-for="item in attention" :key="item.id" class="po-beta__attention">
                    <p class="po-beta__attention-line">
                        <strong class="po-beta__attention-count">{{ item.count }}</strong>
                        {{ item.priority }}
                    </p>
                    <a class="po-beta__link" :href="tabHref(item.tab)">{{ item.actionLabel }} →</a>
                </div>
                <div class="po-beta__section-foot">
                    <a class="po-beta__link" :href="tabHref('lines')">View all order lines →</a>
                </div>
            </section>

            <section class="po-beta__section" data-testid="po-beta-recent-activity">
                <p class="po-beta__label">Recent activity</p>
                <p v-if="!activity" class="po-beta__quiet">No activity yet.</p>
                <button
                    v-else
                    type="button"
                    class="po-beta__activity-row"
                    @click="activityOpen = !activityOpen"
                >
                    <span class="po-beta__activity-mark" aria-hidden="true">✓</span>
                    <span class="po-beta__activity-copy">
                        {{ activity.text }}
                        <template v-if="activity.timestamp">
                            · {{ formatPoActivityTimestamp(activity.timestamp) }}
                        </template>
                    </span>
                    <span aria-hidden="true">∨</span>
                </button>
                <button
                    v-if="earlierActivityCount > 0"
                    type="button"
                    class="po-beta__activity-more"
                    @click="activityOpen = !activityOpen"
                >
                    {{ earlierActivityCount }} earlier updates ∨
                </button>
            </section>
        </div>

        <aside class="po-beta__side">
            <section class="po-beta__section" data-testid="po-beta-health">
                <p class="po-beta__label">Order health</p>
                <p class="po-beta__health-line">
                    <span class="po-beta__health-count">{{ attention.length }}</span><span class="po-beta__health-copy">{{ attention.length === 1 ? ' item needs attention' : ' items need attention' }}</span>
                </p>
                <p class="po-beta__health-break">
                    {{ pricingAttentionRows }} pricing · {{ receivingAttentionRows }} receiving
                </p>
            </section>
            <section class="po-beta__section">
                <p class="po-beta__label">Costs</p>
                <p class="po-beta__cost">
                    <span>Product</span>
                    <span>{{ formatCadDisplay(po.product_total) }}</span>
                </p>
                <p class="po-beta__cost">
                    <span>Shipping + surcharge</span>
                    <span>{{ formatCadDisplay(shippingAndSurcharge) }}</span>
                </p>
                <p class="po-beta__cost is-total">
                    <span><strong>Estimated landed</strong></span>
                    <strong>{{ formatCadDisplay(extras) }}</strong>
                </p>
                <button
                    type="button"
                    class="po-beta__disclosure"
                    data-testid="po-beta-show-calculation"
                    @click="showCalculation = !showCalculation"
                >
                    {{ showCalculation ? 'Hide calculation' : 'Show calculation' }} ∨
                </button>
                <p v-if="showCalculation" class="po-beta__quiet" data-testid="po-beta-calculation">
                    Product + shipping + surcharge = estimated landed
                </p>
            </section>
            <button
                type="button"
                class="po-beta__disclosure-row"
                data-testid="po-beta-order-details"
                @click="detailsOpen = !detailsOpen"
            >
                <span>Order details</span>
                <span class="po-beta__disclosure-value"
                    >{{ po.vendor }} · {{ poShipmentLabel(po.shipment_method) }} ·
                    {{ po.vendor_currency_code }}
                    ∨</span
                >
            </button>
            <p v-if="detailsOpen" class="po-beta__quiet">
                {{
                    po.shipment_tracking_numbers.length > 0
                        ? po.shipment_tracking_numbers.join(', ')
                        : 'No tracking numbers'
                }}
            </p>
            <button type="button" class="po-beta__disclosure-row" @click="notesOpen = !notesOpen">
                <span>Notes</span>
                <span class="po-beta__disclosure-value">{{ notesPreview }} ∨</span>
            </button>
            <p v-if="notesOpen" class="po-beta__quiet">{{ po.notes ?? 'No notes.' }}</p>
        </aside>
    </div>
</template>

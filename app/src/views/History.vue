<template>
    <v-container class="viewContainerWrapper">
        <h1 class="my-2">
            History
        </h1>

        <v-list class="resultsTable">
            <HistoryRow
                v-for="historyRow in historyRowsProps"
                :key="`${historyRow.timestamp}`"
                :name="historyRow.name"
                :aliases="historyRow.aliases"
                :descriptor="historyRow.descriptor"
                :timestamp="historyRow.timestamp"
                :url="historyRow.url"
            />
        </v-list>
    </v-container>
</template>

<script>
import eventBus from '@/eventBus';
import store from '@/services/store';
import HistoryRow from '@/components/HistoryRow';
import utils from '@/js/utils';
import { tuneUrl } from '@/js/tuneUrl';

export default {
    name: 'HistoryView',

    components: {
        HistoryRow,
    },

    data: function () {
        return {
            historyItems: [],
            historyRowsProps: [],
        };
    },

    created: function () {
        eventBus.$emit('parentViewActivated');

        store.getHistoryItems().then((historyItems) => {
            this.historyItems = historyItems.filter(
                historyItem => historyItem.result && !historyItem.result.contour
            );

            this.historyRowsProps = this.historyItems.map((historyItem) => {
                const result = historyItem.result || {};
                const setting = result.setting || {};

                const settingID = result.settingID != null && result.settingID !== ''
                    ? result.settingID
                    : setting.tune_id;

                const url = settingID != null && settingID !== ''
                    ? tuneUrl(String(settingID))
                    : null;

                if (!url) {
                    console.warn('[History: keine URL erzeugt]', {
                        settingID,
                        setting,
                        result
                    });
                }

                return {
                    name: utils.parseDisplayableName(result.displayName),
                    aliases: (result.aliases || []).map(alias =>
                        utils.parseDisplayableName(alias)
                    ),
                    descriptor: utils.parseDisplayableDescription(setting),
                    timestamp: historyItem.timestamp,
                    url: url || '',
                };
            });
        });
    },

    methods: {}
};
</script>

<style scoped>
</style>

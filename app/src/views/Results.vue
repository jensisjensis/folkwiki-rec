<template>
    <v-container
        v-if="lastResults.length"
        class="viewContainerWrapper"
    >
        <h1 class="my-2">
            Results
        </h1>

        <div v-if="lastContour !== null" class="contour-debug">
            <strong>Contour (Debug):</strong>
            <pre>{{ lastContour }}</pre>
        </div>

        <v-list class="resultsTable">
            <ResultRow
                v-for="result in lastResults"
                :key="getSettingID(result) || result.setting.tune_id"
                :setting="result.setting"
                :display-name="result.display_name"
                :aliases="result.aliases || []"
                :setting-i-d="getSettingID(result)"
                :score="result.score"
            />
        </v-list>
    </v-container>

    <v-container v-else>
        <h1 class="my-2">
            Results
        </h1>

        <div v-if="lastContour !== null" class="contour-debug">
            <strong>Contour (Debug):</strong>
            <pre>{{ lastContour }}</pre>
        </div>

        <p>
            Record audio to search the melody database.
        </p>
    </v-container>
</template>

<script>
import ResultRow from '@/components/ResultRow';
import store from '@/services/store';

export default {
    name: 'SearchesView',

    components: {
        ResultRow,
    },

    data() {
        return {
            lastResults: store.state.lastResults,
            lastContour: store.state.lastContour
        };
    },

    methods: {
        getSettingID(result) {
            const settingID = result.settingID !== undefined &&
                result.settingID !== null
                ? result.settingID
                : result.setting_id;

            return settingID !== undefined && settingID !== null
                ? String(settingID)
                : null;
        }
    }
};
</script>

<style scoped>
.resultsTableWrapper {
    display: block;
    max-width: min(90vh, 90vw);
}

.resultsTable > a:nth-child(odd) {
    background: #efefef;
}

.contour-debug {
    margin: 1rem 0;
    padding: 0.75rem;
    background: #f4f4f4;
    overflow-wrap: anywhere;
}

.contour-debug pre {
    white-space: pre-wrap;
    margin-bottom: 0;
}
</style>

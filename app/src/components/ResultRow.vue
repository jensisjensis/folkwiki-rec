<!-- Modified by David Fernández, 2026. See git history for details. -->
<template>
    <a
        class="result-link"
        :href="asturTradUrl || null"
        target="_blank"
        rel="noopener noreferrer"
    >
        <v-container
            v-ripple
            @click="addToHistory"
        >
            <div
                v-if="setting.tunebook"
                class="tunebook-label"
            >
                {{ setting.tunebook }}
            </div>

            <v-row class="pt-1 pb-0">
                <v-col class="py-0 title-row">
                    <h2 class="main-title">
                        {{ name }}
                        <span
                            v-if="hasSetGroup"
                            class="set-group"
                        >
                            in the set <strong>{{ groupName }}</strong>
                        </span>
                    </h2>

                    <span
                        v-if="displayableAliases.length"
                        class="alias-list"
                    >
                        {{ displayableAliases.join(' · ') }}
                    </span>
                </v-col>
            </v-row>

            <v-row class="pb-2 pt-0">
                <v-col class="py-0 descriptor">
                    {{ descriptor }}
                </v-col>

                <v-col
                    v-show="score !== null"
                    class="py-0 text-right score"
                    :style="`color: ${scoreColour};`"
                >
                    {{ scoreLabel }}
                </v-col>
            </v-row>
        </v-container>
    </a>
</template>

<script>
import utils from '@/js/utils.js';
import store from '@/services/store.js';
import { HistoryItem } from '@/js/schema';
import { tuneUrl } from '@/js/tuneUrl';

export default {
    name: 'ResultRow',

    props: {
        setting: {
            type: Object,
            required: true
        },
        displayName: {
            type: String,
            required: true
        },
        aliases: {
            type: Array,
            default: () => [],
            required: false
        },
        settingID: {
            type: String,
            default: null,
            required: false
        },
        score: {
            type: Number,
            default: null,
            required: false
        }
    },

    computed: {
        asturTradUrl: function () {
            return tuneUrl(this.settingID);
        },

        descriptor: function () {
            return utils.parseDisplayableDescription(this.setting);
        },

        name: function () {
            return utils.parseDisplayableName(this.displayName);
        },

        hasSetGroup: function () {
            return this.setting.settuneindex !== undefined &&
                this.setting.settuneindex !== null &&
                Boolean(this.setting.group);
        },

        groupName: function () {
            if (!this.setting.group) {
                return '';
            }

            return utils.parseDisplayableName(this.setting.group);
        },

        displayableAliases: function () {
            return this.aliases.map(alias =>
                utils.parseDisplayableName(alias)
            );
        },

        scoreLabel: function () {
            if (this.score > 0.65) {
                return 'Much similar';
            } else if (this.score > 0.5) {
                return 'Similar';
            } else if (this.score > 0.2) {
                return 'Possible';
            } else if (this.score > 0) {
                return 'Not very likely';
            } else {
                return 'No match';
            }
        },

        scoreColour: function () {
            let x = this.score;
            x = Math.min(0.7, x);
            x = Math.max(0.0, x);
            x = x / 0.7;

            const a = '#CC1111';
            const b = '#11CC11';

            return utils.lerpColor(a, b, x);
        }
    },

    methods: {
        addToHistory() {
            store.addToHistory(new HistoryItem({
                settingID: this.settingID,
                setting: this.setting,
                displayName: this.displayName,
                aliases: this.aliases
            }));
        }
    }
};
</script>

<style scoped>
.result-link {
  display: block;
  width: 100%;
  color: inherit;
  text-decoration: none;
}

.tunebook-label {
  display: block;
  box-sizing: border-box;
  min-height: 1.4em;
  margin-bottom: 4px;
  padding: 2px 0;
  text-align: left;
  font-size: 0.85rem;
  line-height: 1.4;
  color: #777;
  overflow: visible;
  transform: translateY(-3px);
}
.title-row {
  min-width: 0;
}

.main-title {
  margin: 0;
  white-space: normal;
  overflow-wrap: anywhere;
}

.set-group {
  display: block;
  font-size: 0.75em;
  font-weight: normal;
}

.set-group strong {
  font-weight: bold;
}

.alias-list {
  font-size: 1.1em;
  color: #666;
  white-space: normal;
  overflow-wrap: anywhere;
}

.descriptor {
  font-style: italic;
}

.descriptor::first-letter {
  text-transform: uppercase;
}

.score {
  font-weight: bolder;
  font-style: italic;
}

.resultsTable a div {
  background: inherit;
}
</style>

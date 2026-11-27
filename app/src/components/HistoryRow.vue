<template>
    <component
        :is="url ? 'a' : 'div'"
        v-bind="linkAttributes"
        class="history-row"
        :class="{ 'history-link': url }"
    >
        <v-container class="history-row-content" v-ripple>
            <v-row class="pt-1 pb-0">
                <v-col class="py-0 title-row">
                    <h2 class="main-title">{{ name }}</h2>
                    <span
                        v-if="aliases && aliases.length"
                        class="alias-list"
                    >
                        {{ aliases.join(' · ') }}
                    </span>
                </v-col>
            </v-row>

            <v-row class="pb-2 pt-0">
                <v-col class="py-0 descriptor">
                    {{ descriptor }}
                </v-col>
                <v-col class="py-0 text-right timestamp">
                    {{ timestampString }}
                </v-col>
            </v-row>
        </v-container>
    </component>
</template>

<script>
import utils from '@/js/utils';

export default {
    name: 'HistoryRow',

    props: {
        name: {
            type: String,
            required: true
        },
        aliases: {
            type: Array,
            default: () => [],
            required: false
        },
        descriptor: {
            type: String,
            required: true
        },
        timestamp: {
            type: Number,
            required: true
        },
        url: {
            type: String,
            default: '',
            required: false
        }
    },

    computed: {
        timestampString() {
            return utils.utcToString(this.timestamp);
        },

        linkAttributes() {
            if (!this.url) {
                return {};
            }

            return {
                href: this.url,
                target: '_blank',
                rel: 'noopener noreferrer'
            };
        }
    }
};
</script>

<style scoped>
.history-row {
    display: block;
    width: 100%;
    color: inherit;
}

.history-row:nth-child(odd) {
    background-color: #efefef;
}

.history-row:nth-child(even) {
    background-color: #fff;
}

.history-link {
    text-decoration: none;
    cursor: pointer;
}

.history-row-content {
    background: transparent;
}

.title-row {
    display: flex;
    align-items: baseline;
    gap: 0.5em;
    flex-wrap: nowrap;
}

.main-title {
    white-space: nowrap;
    flex-shrink: 0;
}

.alias-list {
    font-size: 1.1em;
    color: #666;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.descriptor {
    font-style: italic;
}

.descriptor::first-letter {
    text-transform: uppercase;
}
</style>

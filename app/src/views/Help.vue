<!-- Modified by Jens Wollschläger, 2026. See git history for details. -->
<template>
    <v-container 
        class="viewContainerWrapper"
    >
        <v-card
            v-if="!isStableRelease"
            class="pa-5 my-2 UnstableRelease"
        >
            <v-icon class="WarningIcon px-3">
                {{ icons.alertCircle }}
            </v-icon>
            <span>The original project is at <a href="https://jensisjensis.github.io/folkwiki-rec/">https://jensisjensis.github.io/folkwiki-rec/</a></span> 
        </v-card>
        <v-card class="pa-5 my-2">
            <h1>About</h1>
            <p>
                <em>Folkwiki - search by recording</em> is a fork of <a href="https://github.com/jensisjensis/Abctransposer---search-by-recording" target="_blank">Abctransposer - Search by recording</a> which searches the website <a href="https://www.abctransposer.de" target="_blank">abctransposer.de</a> database, which contains tunes in the abc format.
            </p>
            <p>
                My version has been adapted for the use with the Swedish site for traditional music from Scandinavia <a href="http://www.folkwiki.se/Musik/Musik">folkwiki.se</a>. 
                In preparation I have downloaded the website's database as sqlite3 and extracted all the abcs contained and assigened the number leading to the respective page on folkwiki.
                 After converting the melodies contained in the abc to the so-called contour format the tunes become reckognizable when recording audio.
                 The project I developed this from is on my website <a href="https://www.abctransposer.de">abctransposer.de</a>. 
                You find the source code of that fork at <a href="https://github.com/jensisjensis/Abctransposer---search-by-recording">https://github.com/jensisjensis/Abctransposer---search-by-recording</a>
            </p>
            <p>
                For the abctransposer website I used the original code from <a href="https://github.com/davidfm/eso-que-ye/tree/main">Eso Qué Ye</a> which was based on is <a href="https://folkfriend.app" target="_blank">FolkFriend</a>,
                originally developed by <a href="https://github.com/TomWyllie" target="_blank">Tom Wyllie</a>,
                who deserves all the credit for the recognition engine, the architecture,
                and the original application. If you play Irish or other folk music traditions,
                visit <a href="https://folkfriend.app" target="_blank">folkfriend.app</a>.
            </p>
            <p>
                This project is licensed under <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank">GPL-3.0</a>.
                The source code is available on <a href="https://github.com/jensisjensis/folkwiki-rec" target="_blank">GitHub</a>.
            </p>
        </v-card>
        <p class="AppInfo">
             Based on FolkFriend v1.3.0 and Eso Qué Ye and Abctransposer - search by recording. <br>© {{ year }} Tom Wyllie (original) · Fork by David Fernández and then forked by Jens Wollschläger
        </p>
    </v-container>
</template>

<script>
import eventBus from '@/eventBus';
import { get } from 'idb-keyval';
import utils from '@/js/utils.js';

import {
    mdiAlertCircle,
    mdiCellphoneArrowDown,
    mdiCheckCircleOutline,
    mdiDotsVertical,
    mdiExportVariant,
    mdiPlusBoxOutline,
} from '@mdi/js';

export default {
    name: 'HelpView',
    data: () => ({
        icons: {
            alertCircle: mdiAlertCircle,
            iosShare: mdiExportVariant,
            iosAddToHomeScreen: mdiPlusBoxOutline,
            checkCircle: mdiCheckCircleOutline,
            // TODO these haven't been added yet
            // mdiInstallDesktop: mdiInstallDesktop,
            // mdiInstallMobile: mdiInstallMobile,
            cellphoneArrowDown: mdiCellphoneArrowDown,
            dotsVertical: mdiDotsVertical,
        },
        isStableRelease: utils.isStableRelease(),
        appVersion: process.env.PACKAGE_VERSION,
        year: new Date().getFullYear(),
        tuneIndexVersion: '...',
    }),
    computed: {},
    created: async function () {
        eventBus.$emit('parentViewActivated');
        const meta = await get('tuneIndexMetadata');
        if (meta) this.tuneIndexVersion = meta.v;
    },
    mounted: function () {
        if(this.download) {
            this.$refs['helpDownload'].$el.scrollIntoView();
        } else if(this.share) {
            this.$refs['helpShare'].$el.scrollIntoView();
        }
    },
};
</script>

<style scoped>
.feedbackEmail {
    font-weight: bold;
    color: --var(--v-primary-base);
}

.v-card {
    scroll-margin-top: 60px;
}

.AppInfo {
    color: dimgray;
    text-align: center;
    font-size: smaller;
}

.Installed {
    color: var(--v-secondary-base);
}

.QRCode {
    max-width: 240px;
    min-width: 100px;
    width: 40vw;
    fill: var(--v-primary-darken1);
}

.UnstableRelease {
    background: var(--v-secondary-lighten5);
}

.WarningIcon {
    animation: blinker 1.5s linear infinite;
    color: var(--v-secondary-base);
}

@keyframes blinker {
    50% {
        opacity: 0.2;
    }
}
</style>
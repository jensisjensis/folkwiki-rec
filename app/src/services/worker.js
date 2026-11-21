// Modified by David Fernández, 2026. See git history for details.

import * as Comlink from '@/js/comlink';
import ffConfig from '@/ffConfig';
import { get, set } from 'idb-keyval';

class FolkFriendWASMWrapper {
    constructor() {
        this.folkfriendWASM = null;

        this.loadedWASM = new Promise(resolve => {
            this.setLoadedWASM = resolve;
        });
        this.loadedIndex = new Promise(resolve => {
            this.setLoadedIndex = resolve;
        });
        this.loadedSampleRate = new Promise(resolve => {
            this.setLoadedSampleRate = resolve;
        });

        import('@/wasm/folkfriend.js').then(wasm => {
            this.folkfriendWASM = new wasm.FolkFriendWASM();
            this.setLoadedWASM();
        });
    }

    async version(cb) {
        await this.loadedWASM;
        cb(this.folkfriendWASM.version());
    }

    async onIndexLoad(cb) {
        await this.loadedWASM;
        await this.loadedIndex;
        cb();
    }

    async fetchTuneIndexMetadata() {
        // Ein eindeutiger Query-Parameter verhindert, dass ein Browser
        // oder zwischengeschalteter Cache alte Metadaten zurückgibt.
        const url = '/nud-meta.json?_=' + Date.now();

        const response = await fetch(url, {
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error(
                'Could not fetch tune index metadata: HTTP ' +
                response.status
            );
        }

        const metadata = await response.json();

        if (!metadata || metadata.v === undefined || metadata.v === null) {
            throw new Error('Tune index metadata is missing the "v" field');
        }

        return metadata;
    }

    async fetchTuneIndexData(version) {
        if (version === undefined || version === null) {
            throw new Error('A tune index version is required');
        }

        console.time('index-fetch');

        try {
            // "v" identifiziert die Datenversion. Der zusätzliche
            // Zeitstempel umgeht auch Caches, die Query-Parameter speziell
            // behandeln oder deren Cache-Regeln nicht korrekt übernehmen.
            const url =
                '/folkfriend-non-user-data.json?v=' +
                encodeURIComponent(String(version)) +
                '&_=' +
                Date.now();

            const response = await fetch(url, {
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(
                    'Could not fetch tune index: HTTP ' + response.status
                );
            }

            return {
                indexData: await response.json()
            };
        } finally {
            console.timeEnd('index-fetch');
        }
    }

    async setupTuneIndex(cb) {
        // Lädt den Tune-Index beim Start und hält eine Offline-Kopie vor.
        const t0 = performance.now();
        const analyticsData = {
            newly_installed: false,
            newly_updated: false
        };

        console.time('tune-index-setup');

        try {
            const localTuneIndex = await get('tuneIndex');

            if (typeof localTuneIndex === 'undefined') {
                console.debug(
                    'No tune index was cached, requesting download'
                );

                console.time('tune-index-load');

                let tuneIndexMetadata;
                let downloadedTuneIndex;

                try {
                    // Zuerst die aktuelle Version abrufen. Sie wird
                    // anschließend beim Abruf der Indexdatei mitgeschickt.
                    tuneIndexMetadata =
                        await this.fetchTuneIndexMetadata();

                    downloadedTuneIndex =
                        await this.fetchTuneIndexData(
                            tuneIndexMetadata.v
                        );

                    await this.loadTuneIndex(downloadedTuneIndex);
                } finally {
                    console.timeEnd('tune-index-load');
                }

                await set('tuneIndex', downloadedTuneIndex);
                await set('tuneIndexMetadata', tuneIndexMetadata);

                analyticsData.days_since_update = 0;
                analyticsData.tune_index_metadata_version =
                    tuneIndexMetadata.v;
                analyticsData.newly_installed = true;
            } else {
                console.debug('Found cached tune index');

                // Den vorhandenen Index sofort laden, damit die Anwendung
                // auch bei fehlender Netzwerkverbindung nutzbar bleibt.
                console.time('tune-index-load');

                try {
                    await this.loadTuneIndex(localTuneIndex);
                } finally {
                    console.timeEnd('tune-index-load');
                }

                let tuneIndexMetadataLocal =
                    await get('tuneIndexMetadata');

                if (typeof tuneIndexMetadataLocal === 'undefined') {
                    // Die Metadaten können fehlen, wenn nur ein Teil des
                    // lokalen Caches gelöscht wurde.
                    tuneIndexMetadataLocal = { v: 0 };
                }

                analyticsData.tune_index_metadata_version =
                    tuneIndexMetadataLocal.v;

                try {
                    // Durch den Cache-Buster wird die aktuelle Serverversion
                    // geprüft, statt eventuell alte Metadaten zu verwenden.
                    const tuneIndexMetadataRemote =
                        await this.fetchTuneIndexMetadata();

                    const remoteVersion =
                        String(tuneIndexMetadataRemote.v);
                    const localVersion =
                        String(tuneIndexMetadataLocal.v);

                    console.debug(
                        'Tune index remote: ' + remoteVersion +
                        ', local: ' + localVersion
                    );

                    if (remoteVersion !== localVersion) {
                        console.debug('Upgrading tune index');

                        const downloadedTuneIndex =
                            await this.fetchTuneIndexData(
                                tuneIndexMetadataRemote.v
                            );

                        // Die neue Datenversion in WASM laden und danach
                        // im lokalen IndexedDB-Cache speichern.
                        await this.loadTuneIndex(downloadedTuneIndex);
                        await set('tuneIndex', downloadedTuneIndex);
                        await set(
                            'tuneIndexMetadata',
                            tuneIndexMetadataRemote
                        );

                        analyticsData.days_since_update = 0;
                        analyticsData.tune_index_metadata_version =
                            tuneIndexMetadataRemote.v;
                        analyticsData.newly_updated = true;
                    }
                } catch (error) {
                    // Bei Netzwerk- oder Ladefehlern wird der lokale Index
                    // weiterhin verwendet.
                    console.warn(
                        'Could not check for or load a tune index update; ' +
                        'using the cached index.',
                        error
                    );
                }
            }

            analyticsData.wall_time = performance.now() - t0;
            cb(analyticsData);
        } finally {
            console.timeEnd('tune-index-setup');
        }
    }

    async loadTuneIndex(tuneIndex) {
        console.time('tune-index-to-wasm');

        try {
            await this.loadedWASM;
            await this.folkfriendWASM.load_index_from_json_obj(
                tuneIndex.indexData
            );
            this.setLoadedIndex();
        } finally {
            console.timeEnd('tune-index-to-wasm');
        }
    }

    async setSampleRate(sampleRate) {
        await this.loadedWASM;

        // Ungültige Abtastraten werden vom WASM-Backend ignoriert;
        // es behält dann seinen Standardwert.
        await this.folkfriendWASM.set_sample_rate(sampleRate);
        this.setLoadedSampleRate();
    }

    async feedEntirePCMSignal(PCMSignal) {
        const frames = Math.floor(
            PCMSignal.length / ffConfig.SPEC_WINDOW_SIZE
        );

        if (frames === 0) {
            throw new Error('PCM signal too short');
        }

        for (let i = 0; i < frames; i++) {
            const PCMWindow = PCMSignal.slice(
                ffConfig.SPEC_WINDOW_SIZE * i,
                ffConfig.SPEC_WINDOW_SIZE * (i + 1)
            );
            await this.feedSinglePCMWindow(PCMWindow);
        }
    }

    async feedSinglePCMWindow(PCMWindow) {
        await this.loadedWASM;
        await this.loadedSampleRate;

        const ptr = await this.folkfriendWASM.alloc_single_pcm_window();
        const arr = await this.folkfriendWASM.get_allocated_pcm_window(ptr);

        arr.set(PCMWindow);

        await this.folkfriendWASM.feed_single_pcm_window(ptr);
    }

    async flushPCMBuffer() {
        await this.loadedWASM;
        await this.folkfriendWASM.flush_pcm_buffer();
    }

    async transcribePCMBuffer(cb) {
        try {
            await this.loadedWASM;

            const contour =
                await this.folkfriendWASM.transcribe_pcm_buffer();

            cb(contour);
        } catch (error) {
            console.error(error);
            console.warn('Aborting transcribePCMBuffer');

            cb(JSON.stringify({
                error: 'An error ocurred whilst transcribing audio.'
            }));
        }
    }

    async runTranscriptionQuery(query, cb) {
        await this.loadedWASM;
        await this.loadedIndex;

        const response =
            await this.folkfriendWASM.run_transcription_query(query);

        cb(JSON.parse(response));
    }

    async tuningOffsetCents(cb) {
        await this.loadedWASM;
        cb(this.folkfriendWASM.tuning_offset_cents());
    }

    async transcribePCMBufferSnapshot(cb) {
        try {
            await this.loadedWASM;

            const contour =
                await this.folkfriendWASM.transcribe_pcm_buffer_snapshot();

            cb(contour);
        } catch (error) {
            console.error(error);

            cb(JSON.stringify({
                error: 'An error ocurred whilst transcribing audio.'
            }));
        }
    }

    async runNameQuery(query, cb) {
        await this.loadedWASM;
        await this.loadedIndex;

        const response =
            await this.folkfriendWASM.run_name_query(query);

        cb(JSON.parse(response));
    }
}

const folkfriendWASMWrapper = new FolkFriendWASMWrapper();
Comlink.expose(folkfriendWASMWrapper);

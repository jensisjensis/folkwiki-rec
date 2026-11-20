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
        const response = await fetch('/nud-meta.json', {
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
            const url =
                '/folkfriend-non-user-data.json?v=' +
                encodeURIComponent(String(version));

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
        // Load the tune index at startup and keep an offline copy.
        const t0 = performance.now();
        const analyticsData = {
            newly_installed: false,
            newly_updated: false
        };

        console.time('tune-index-setup');

        try {
            const localTuneIndex = await get('tuneIndex');

            if (typeof localTuneIndex === 'undefined') {
                console.debug('No tune index was cached, requesting download');

                console.time('tune-index-load');

                let tuneIndexMetadata;
                let downloadedTuneIndex;

                try {
                    // Read the version first so it can be used as the
                    // query parameter when downloading the tune index.
                    tuneIndexMetadata = await this.fetchTuneIndexMetadata();
                    downloadedTuneIndex = await this.fetchTuneIndexData(
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

                // Load the cached index immediately so the app remains usable
                // while checking whether a newer version is available.
                console.time('tune-index-load');

                try {
                    await this.loadTuneIndex(localTuneIndex);
                } finally {
                    console.timeEnd('tune-index-load');
                }

                let tuneIndexMetadataLocal = await get('tuneIndexMetadata');

                if (typeof tuneIndexMetadataLocal === 'undefined') {
                    // Metadata may be missing if it was cleared separately
                    // from the cached tune index.
                    tuneIndexMetadataLocal = { v: 0 };
                }

                analyticsData.tune_index_metadata_version =
                    tuneIndexMetadataLocal.v;

                try {
                    const tuneIndexMetadataRemote =
                        await this.fetchTuneIndexMetadata();

                    const remoteVersion = String(tuneIndexMetadataRemote.v);
                    const localVersion = String(tuneIndexMetadataLocal.v);

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

                        // Update WASM and the offline IndexedDB copy.
                        await this.loadTuneIndex(downloadedTuneIndex);
                        await set('tuneIndex', downloadedTuneIndex);
                        await set('tuneIndexMetadata', tuneIndexMetadataRemote);

                        analyticsData.days_since_update = 0;
                        analyticsData.tune_index_metadata_version =
                            tuneIndexMetadataRemote.v;
                        analyticsData.newly_updated = true;
                    }
                } catch (error) {
                    // Keep using the cached index if the network or update
                    // request is unavailable.
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

        // Invalid sample rates are ignored by the WASM backend, which
        // retains its default. The caller should validate the value.
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

        const response = await this.folkfriendWASM.run_name_query(query);
        cb(JSON.parse(response));
    }
}

const folkfriendWASMWrapper = new FolkFriendWASMWrapper();
Comlink.expose(folkfriendWASMWrapper);

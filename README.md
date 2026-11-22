# Folkwiki-rec - search by recording

A web app for recognising and transcribing music in the abc-format. It listens to a recorded melody, reckognizes it via contour and searches the abcs from http://www.folkwiki.se/Musik/Musik for matches. I'm providing the file app/public/abc-zu-folkfriend-json.html which converts tune-files in the abc-format (.abc) into the file folkfriend-non-user-data.json containing the essential information on how the tunes are found via contour and linked to the project It runs entirely in the browser with no internet connection required if you are only interested in the tune names.

> This project is a fork of [Abctransposer---search-by-recording](https://github.com/jensisjensis/Abctransposer---search-by-recording) being a fork of [eso-que-ye](https://github.com/davidfm/eso-que-ye) by [davidfm](https://github.com/davidfm) being
>  a fork of [FolkFriend](https://github.com/TomWyllie/folkfriend)
> by [Tom Wyllie](https://github.com/TomWyllie), adapted to use the AsturTrad library.
> All credit for the recognition engine, architecture, and original app goes to Tom.
> The original app lives at [folkfriend.app](https://folkfriend.app).

## License

This project is licensed under [GPL-3.0](LICENSE). As required by the license, the source code is open, all original copyright notices are preserved, and modifications are noted.

---

## Getting started

### Prerequisites

Install [mise](https://mise.jdx.dev) to manage Rust and Node:

```bash
curl https://mise.run | sh
# Follow the prompt to activate mise in your shell, then:
mise install
```

### Setup

```bash
mise run setup      # install wasm-pack and npm dependencies (once)
mise run wasm       # compile Rust → WebAssembly (re-run when Rust code changes)
```

Download the latest tune index from the AsturTrad releases into `app/public/` (needs `gh auth login`):

```bash
mise run index      # re-run to pick up new tunes
```

### Development server

```bash
mise run dev        # starts at http://localhost:8080
```

### Production build

```bash
mise run build      # output in app/dist/
```

---

## Repository structure

| Directory | Description |
|---|---|
| `app/` | Vue.js PWA source code |
| `rust/` | Recognition engine source code (Rust, compiled to WASM) |
| `rust/wavs/` | Sample audio files for testing |


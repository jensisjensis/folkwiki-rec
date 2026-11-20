use serde::{Deserialize, Serialize};
use std::collections;

pub type TuneSettings = collections::HashMap<SettingID, Setting>;
pub type TuneAliases = collections::HashMap<TuneID, Vec<SettingID>>;

// These are very deliberately strings of integers.
//   Otherwise rust / wasm / serde / etc. problems.
pub type TuneID = String;
pub type SettingID = String;

#[derive(Serialize, Deserialize, Debug, Clone)]
pub struct Setting {
    pub tune_id: TuneID,
    pub meter: String,
    pub mode: String,
    pub dance: String,
    pub contour: String,

    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub tunebook: Option<String>,

    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub index: Option<u32>,

    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub group: Option<String>,

    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub settuneindex: Option<u32>,
}

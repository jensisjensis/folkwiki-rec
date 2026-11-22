use serde::{Deserialize, Serialize};
use std::collections;

pub type TuneSettings = collections::HashMap<SettingID, Setting>;
pub type TuneAliases = collections::HashMap<TuneID, Vec<SettingID>>;

// Deliberately strings of integers.
pub type TuneID = String;
pub type SettingID = String;

#[derive(Serialize, Deserialize, Debug, Clone)]
pub struct Setting {
    pub tune_id: TuneID,
    pub meter: String,
    pub mode: String,
    pub dance: String,
    pub contour: String,
}

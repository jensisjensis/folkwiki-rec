Once you have cloned this project follow these steps:

cd folkwiki-rec

mise install
mise run setup
mise run wasm

mise run dev

******************************************************

In order to generate the required file folkfriend-non-user-data.json you must download the folkwiki database by running the python script

python3 step1_folkwiki_sqlite_export.py

It might take around 2 hours and it will generate a file folkwiki_abc.sqlite3 - about 6MB in size. 

Then in order to extract the abc contained in the database and connect it with the index numbers relevant for finding the tune on the website run

python3 step2_export_abc.py

It will generate the file folkwiki_relevant.abc

Now run 

step3_abc-to-folkfriend-json.html

and choose the file folkwiki_relevant.abc

Press the button "Generate and save JSON".

Two files are generated:

folkfriend-non-user-data.json
nud-meta.json

Copy those two into the folder

app/public

--> You're set. Run 

mise run dev 

for the development server or

mise run build

for production

If built for production you will find the files and subdirectories to upload at 

app/dist     

Enjoy.
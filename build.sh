#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "$0")"

version=$(php -r 'echo simplexml_load_file("dharmamedia.xml")->version;')
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf 'Invalid release version: %s\n' "$version" >&2
    exit 1
fi

mkdir -p dist
name="plg_system_dharmamedia-${version}.zip"
package="dist/$name"
rm -f -- "$package"
zip -q -X -r "$package" dharmamedia.xml services src layouts media language LICENSE -x '*/.DS_Store'
checksum=$(shasum -a 256 "$package")
checksum=${checksum%% *}

cat > updates.xml <<XML
<?xml version="1.0" encoding="utf-8"?>
<updates>
    <update>
        <name>Dharma Media</name>
        <description>Upload and optimise images in Joomla Media fields</description>
        <element>dharmamedia</element>
        <type>plugin</type>
        <folder>system</folder>
        <client>site</client>
        <version>$version</version>
        <infourl title="Release $version">https://github.com/shivayanamahom/dharma-media/releases/tag/v$version</infourl>
        <downloads>
            <downloadurl type="full" format="zip">https://github.com/shivayanamahom/dharma-media/releases/download/v$version/$name</downloadurl>
        </downloads>
        <sha256>$checksum</sha256>
        <tags><tag>stable</tag></tags>
        <targetplatform name="joomla" version="6\.[0-9]+" />
        <php_minimum>8.3.0</php_minimum>
    </update>
</updates>
XML

printf '%s\n' "$package"

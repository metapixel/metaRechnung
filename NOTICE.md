# Lizenz und Drittbibliotheken

metaRechnung: Copyright (C) 2026 Kai. Lizenz: GNU General Public License, Version 2 oder später (GPL-2.0-or-later), Text in `LICENSE`.
Dieses Programm wird in der Hoffnung verteilt, dass es nützlich ist, aber **ohne jede Gewährleistung**, auch ohne die implizite Gewährleistung der
Marktreife oder der Eignung für einen bestimmten Zweck. Es ersetzt keine steuerliche oder rechtliche Beratung.

Die Installationspakete enthalten unter `vendor/` folgende Bibliotheken mit ihren eigenen Lizenzen (die Lizenztexte liegen jeweils im Paketordner):

| Paket | Lizenz |
|---|---|
| mpdf/mpdf (und Hilfspakete) | GPL-2.0-only |
| phpmailer/phpmailer | LGPL-2.1-only |
| horstoeko/zugferd, horstoeko/mimedb, horstoeko/stringmanagement | MIT |
| setasign/fpdi, setasign/fpdf | MIT bzw. frei nutzbar (siehe Paket) |
| symfony/* | MIT |
| jms/serializer, jms/metadata, doctrine/*, goetas-webservices/xsd2php-runtime | MIT |
| smalot/pdfparser | LGPL-3.0 |
| psr/*, myclabs/deep-copy, phpstan/phpdoc-parser, paragonie/random_compat | MIT |
| DejaVu-Schriften (in mPDF) | freie Bitstream-Vera-/DejaVu-Lizenz |

Hinweis: mPDF steht unter GPL-2.0-only. Das Gesamtpaket (Anwendung + mPDF) wird deshalb unter GPL-2.0 verbreitet; wer es weitergibt, muss den Quellcode
dazu bereitstellen (das Repository bzw. der Quelltext der Pakete genügt).

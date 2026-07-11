# Mailserver

This project can run a domain mail server through `docker-mailserver` and a
Roundcube webmail UI on a separate local port. The existing Cloudflare Tunnel
can publish Roundcube without taking over the Laravel app port.

## Local configuration

Add these values to `.env` and replace the domain when needed:

```dotenv
MAILSERVER_HOSTNAME=mail.settlane.tech
MAILSERVER_POSTMASTER_ADDRESS=postmaster@settlane.tech
MAILSERVER_SSL_TYPE=
WEBMAIL_HOSTNAME=mail.settlane.tech
ROUNDCUBE_UPLOAD_MAX_FILESIZE=25M
ROUNDCUBE_PORT=8081
MAILSERVER_SMTP_PORT=25
MAILSERVER_IMAP_PORT=143
MAILSERVER_SUBMISSION_PORT=587
MAILSERVER_SMTPS_PORT=465
MAILSERVER_IMAPS_PORT=993
MAILSERVER_ENABLE_CLAMAV=0
MAILSERVER_ENABLE_FAIL2BAN=1
MAILSERVER_ENABLE_OPENDKIM=1
MAILSERVER_ENABLE_OPENDMARC=1
```

## Run

```bash
docker compose up -d mailserver roundcube
```

Roundcube is served locally at:

```text
http://localhost:8081
```

For Cloudflare Tunnel, add a public hostname:

```text
mail.settlane.tech -> http://localhost:8081
```

Cloudflare will terminate HTTPS for the webmail UI at:

```text
https://mail.settlane.tech
```

Keep the existing app hostname pointing to Laravel on:

```text
http://localhost:80
```

## Create mailbox

```bash
docker compose exec mailserver setup email add user@settlane.tech 'strong-password-here'
docker compose exec mailserver setup email list
```

## DKIM

Generate and read the DKIM key:

```bash
docker compose exec mailserver setup config dkim
docker compose exec mailserver cat /tmp/docker-mailserver/opendkim/keys/settlane.tech/mail.txt
```

## DNS

For `settlane.tech`, publish these mail records at the DNS provider:

```text
settlane.tech.       MX   10 mail.settlane.tech.
settlane.tech.       TXT  "v=spf1 mx -all"
_dmarc.settlane.tech TXT  "v=DMARC1; p=quarantine; rua=mailto:postmaster@settlane.tech"
```

Also publish the DKIM TXT record printed by the command above.

Important: a Cloudflare Tunnel can publish Roundcube webmail, but it does not
make SMTP delivery work for the public internet. For receiving normal email
directly on this machine, `mail.settlane.tech` must resolve to a public IP that
can accept SMTP on port 25. A private LAN address like `192.168.110.64` only
works inside the local network.

If you expose SMTP directly, the server firewall/router must allow inbound TCP
ports:

```text
25, 143, 465, 587, 993
```

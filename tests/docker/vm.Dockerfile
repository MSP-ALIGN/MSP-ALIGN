# A throwaway "VM" for testing install.sh in CI: Debian 13 with systemd as PID 1 (run with --privileged).
ARG BASE=debian:trixie
FROM ${BASE}
ARG DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends systemd systemd-sysv dbus tzdata ca-certificates curl git sudo procps iproute2 >/dev/null \
 && rm -rf /var/lib/apt/lists/* \
 && find /etc/systemd/system /lib/systemd/system -path '*.wants/*' \( -name '*getty*' -o -name '*udev*' -o -name 'systemd-remount-fs*' \) -delete
STOPSIGNAL SIGRTMIN+3
CMD ["/lib/systemd/systemd"]

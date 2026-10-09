#!/usr/bin/env python3
"""Send one hand-built Access-Request to 127.0.0.1:1812 and print ANSWERED or DROPPED.

radclient always adds Message-Authenticator, so the router checks in radius_policy_tests.sh build their
packets here. Usage: radius_blast_probe.py <source ip> <secret> <mode>
Modes: plain, proxy-state, ma, ma-proxy-state, bad-ma (a wrong Message-Authenticator).
"""
import hashlib
import hmac
import os
import socket
import struct
import sys

MODES = ("plain", "proxy-state", "ma", "ma-proxy-state", "bad-ma")


def attribute(kind, value):
    return struct.pack("BB", kind, len(value) + 2) + value


def hide_password(password, secret, authenticator):
    padded = password + b"\0" * (-len(password) % 16)
    hidden, previous = b"", authenticator
    for i in range(0, len(padded), 16):
        key = hashlib.md5(secret + previous).digest()
        block = bytes(a ^ b for a, b in zip(padded[i:i + 16], key))
        hidden += block
        previous = block
    return hidden


def main():
    if len(sys.argv) != 4 or sys.argv[3] not in MODES:
        sys.exit(f"usage: {sys.argv[0]} <source ip> <secret> <{'|'.join(MODES)}>")
    source, secret, mode = sys.argv[1], sys.argv[2].encode(), sys.argv[3]
    authenticator = os.urandom(16)
    attributes = (attribute(1, b"t_probe")
                  + attribute(2, hide_password(b"x", secret, authenticator))
                  + attribute(4, socket.inet_aton(source)))
    if "proxy-state" in mode:
        attributes += attribute(33, b"t_probe_state")
    signed = mode in ("ma", "ma-proxy-state", "bad-ma")
    if signed:
        attributes += attribute(80, b"\0" * 16)
    packet = struct.pack("!BBH", 1, os.urandom(1)[0], 20 + len(attributes)) + authenticator + attributes
    if signed:
        signature = os.urandom(16) if mode == "bad-ma" else hmac.new(secret, packet, hashlib.md5).digest()
        packet = packet[:-16] + signature
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind((source, 0))
    sock.settimeout(2)
    sock.sendto(packet, ("127.0.0.1", 1812))
    try:
        sock.recvfrom(4096)
        print("ANSWERED")
    except socket.timeout:
        print("DROPPED")


main()

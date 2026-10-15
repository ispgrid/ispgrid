#!/usr/bin/env python3
"""Send one load-test phase to the local "isp" server and print one result line.

Used by radius_load_test.sh, which creates the customers lt_00001.. first. radclient is not used: with -f and
-p it quits before every reply has arrived (FreeRADIUS 3.2.10), so its counts are wrong under load.

Usage: radius_load_send.py <auth|start|stop> <count> <parallel> <secret>
Prints: <answered> <rejected> <lost> <milliseconds>
Keeps <parallel> requests in flight; a request without a reply after 10 seconds is lost (no retries).
"""
import hashlib
import hmac
import os
import selectors
import socket
import struct
import sys
import time

SERVER = "127.0.0.1"
TIMEOUT = 10.0
IDS_PER_SOCKET = 256
ACCESS_ACCEPT, ACCESS_REJECT, ACCOUNTING_RESPONSE = 2, 3, 5


def attribute(kind, value):
    return struct.pack("BB", kind, len(value) + 2) + value


def integer(kind, value):
    return attribute(kind, struct.pack("!I", value))


def hide_password(password, secret, authenticator):
    padded = password + b"\0" * (-len(password) % 16)
    hidden, previous = b"", authenticator
    for i in range(0, len(padded), 16):
        key = hashlib.md5(secret + previous).digest()
        block = bytes(a ^ b for a, b in zip(padded[i:i + 16], key))
        hidden += block
        previous = block
    return hidden


def customer(i):
    """The same username, MAC and address radius_load_test.sh gives customer i."""
    mac = "02:4C:54:%02X:%02X:%02X" % ((i >> 16) & 255, (i >> 8) & 255, i & 255)
    address = socket.inet_aton("100.%d.%d.%d" % (64 + (i >> 16), (i >> 8) & 255, i & 255))
    return ("lt_%05d" % i).encode(), mac.encode(), address


def access_request(i, packet_id, secret):
    """A PAP PPPoE login from the localhost NAS, signed with Message-Authenticator like radclient."""
    username, mac, _ = customer(i)
    authenticator = os.urandom(16)
    attributes = (attribute(1, username)
                  + attribute(2, hide_password(b"pass", secret, authenticator))
                  + integer(6, 2)                       # Service-Type = Framed-User
                  + integer(7, 1)                       # Framed-Protocol = PPP
                  + attribute(31, mac)                  # Calling-Station-Id
                  + attribute(30, b"pppoe-load")        # Called-Station-Id
                  + attribute(4, socket.inet_aton(SERVER))
                  + attribute(80, b"\0" * 16))          # Message-Authenticator, filled in below
    packet = struct.pack("!BBH", 1, packet_id, 20 + len(attributes)) + authenticator + attributes
    return packet[:-16] + hmac.new(secret, packet, hashlib.md5).digest()


def accounting_request(i, packet_id, secret, status):
    username, mac, address = customer(i)
    attributes = (attribute(1, username)
                  + integer(40, status)                 # Acct-Status-Type: 1 Start, 2 Stop
                  + attribute(44, b"lt-%d" % i)         # Acct-Session-Id
                  + attribute(4, socket.inet_aton(SERVER))
                  + attribute(8, address)               # Framed-IP-Address
                  + attribute(31, mac))
    if status == 2:
        attributes += integer(46, 60) + integer(42, 1000000) + integer(43, 5000000)
    header = struct.pack("!BBH", 4, packet_id, 20 + len(attributes))
    authenticator = hashlib.md5(header + b"\0" * 16 + attributes + secret).digest()
    return header + authenticator + attributes


def main():
    if len(sys.argv) != 5 or sys.argv[1] not in ("auth", "start", "stop"):
        sys.exit(f"usage: {sys.argv[0]} <auth|start|stop> <count> <parallel> <secret>")
    phase, count, parallel, secret = sys.argv[1], int(sys.argv[2]), int(sys.argv[3]), sys.argv[4].encode()
    port = 1812 if phase == "auth" else 1813

    def build(i, packet_id):
        if phase == "auth":
            return access_request(i, packet_id, secret)
        return accounting_request(i, packet_id, secret, 1 if phase == "start" else 2)

    sockets = []
    free_ids = []                                       # (socket index, id) pairs not in use
    selector = selectors.DefaultSelector()

    def add_socket():
        """Each socket gives 256 more request IDs; IDs of lost requests are never reused."""
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
        sock.bind(("127.0.0.1", 0))
        sock.setblocking(False)
        selector.register(sock, selectors.EVENT_READ, len(sockets))
        free_ids.extend((len(sockets), packet_id) for packet_id in range(IDS_PER_SOCKET))
        sockets.append(sock)

    in_flight = {}                                      # (socket index, id) -> time sent
    answered = rejected = lost = 0
    next_customer = 1
    started = time.monotonic()

    while next_customer <= count or in_flight:
        while next_customer <= count and len(in_flight) < parallel:
            if not free_ids:
                add_socket()
            s, packet_id = free_ids.pop(0)
            sockets[s].sendto(build(next_customer, packet_id), (SERVER, port))
            in_flight[(s, packet_id)] = time.monotonic()
            next_customer += 1

        for key, _ in selector.select(timeout=0.5):
            while True:
                try:
                    reply = key.fileobj.recv(4096)
                except BlockingIOError:
                    break
                if len(reply) < 20 or (key.data, reply[1]) not in in_flight:
                    continue
                del in_flight[(key.data, reply[1])]
                free_ids.append((key.data, reply[1]))
                if reply[0] == ACCESS_REJECT:
                    rejected += 1
                elif reply[0] in (ACCESS_ACCEPT, ACCOUNTING_RESPONSE):
                    answered += 1

        now = time.monotonic()
        for request, sent in list(in_flight.items()):
            if now - sent > TIMEOUT:
                del in_flight[request]
                lost += 1                               # its ID is never reused: a late reply is ignored

    print(answered, rejected, lost, int((time.monotonic() - started) * 1000))


if __name__ == "__main__":
    main()

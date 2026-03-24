#!/usr/bin/env python3
"""
FamTask API-Server — nur für /api/data
Nginx serviert die statischen Dateien, dieser Server nur die Daten-API.
Starten: python3 server.py
Läuft auf Port 8081 (intern, nginx proxied /api/ dorthin)
"""

import json
import os
import sys
import shutil
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse

DATA_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "data.json")
PORT = 8081

def load_data():
    if os.path.exists(DATA_FILE):
        with open(DATA_FILE, "r", encoding="utf-8") as f:
            return f.read()
    return "{}"

def save_data(body):
    json.loads(body)  # Validate JSON
    # Atomic write via temp file
    tmp = DATA_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        f.write(body)
    shutil.move(tmp, DATA_FILE)

class Handler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        print(f"[FamTask] {args[0]} {args[1]}")

    def do_OPTIONS(self):
        self.send_response(200)
        self._cors()
        self.end_headers()

    def do_GET(self):
        if urlparse(self.path).path == "/api/data":
            data = load_data()
            self.send_response(200)
            self._cors()
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(data.encode())))
            self.end_headers()
            self.wfile.write(data.encode())
        else:
            self.send_response(404)
            self.end_headers()

    def do_POST(self):
        if urlparse(self.path).path == "/api/data":
            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length).decode("utf-8")
            try:
                save_data(body)
                resp = b'{"ok":true}'
                self.send_response(200)
                self._cors()
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(resp)))
                self.end_headers()
                self.wfile.write(resp)
            except Exception as e:
                resp = f'{{"error":"{str(e)}"}}'.encode()
                self.send_response(400)
                self._cors()
                self.end_headers()
                self.wfile.write(resp)
        else:
            self.send_response(404)
            self.end_headers()

    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

if __name__ == "__main__":
    server = HTTPServer(("127.0.0.1", PORT), Handler)
    print(f"✨ FamTask API läuft auf http://127.0.0.1:{PORT}")
    print(f"   Daten: {DATA_FILE}")
    print(f"   Nginx muss /api/ → http://127.0.0.1:{PORT} proxien\n")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nGestoppt.")
        sys.exit(0)

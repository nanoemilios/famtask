#!/usr/bin/env python3
"""
FamTask Backend — speichert alle Daten in data.json
Starten: python3 server.py
"""

import json
import os
import sys
from http.server import HTTPServer, SimpleHTTPRequestHandler
from urllib.parse import urlparse

DATA_FILE = os.path.join(os.path.dirname(__file__), "data.json")
PORT = 8080

def load_data():
    if os.path.exists(DATA_FILE):
        with open(DATA_FILE, "r", encoding="utf-8") as f:
            return f.read()
    return "{}"

def save_data(body):
    # Validate it's valid JSON before saving
    json.loads(body)
    with open(DATA_FILE, "w", encoding="utf-8") as f:
        f.write(body)

class Handler(SimpleHTTPRequestHandler):
    def log_message(self, format, *args):
        pass  # Quiet logging

    def do_OPTIONS(self):
        self.send_response(200)
        self._cors()
        self.end_headers()

    def do_GET(self):
        path = urlparse(self.path).path

        if path == "/api/data":
            data = load_data()
            self.send_response(200)
            self._cors()
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(data.encode())

        elif path in ("/", "/index.html"):
            self.path = "/index.html"
            return SimpleHTTPRequestHandler.do_GET(self)

        else:
            return SimpleHTTPRequestHandler.do_GET(self)

    def do_POST(self):
        path = urlparse(self.path).path

        if path == "/api/data":
            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length).decode("utf-8")
            try:
                save_data(body)
                self.send_response(200)
                self._cors()
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b'{"ok":true}')
            except Exception as e:
                self.send_response(400)
                self._cors()
                self.end_headers()
                self.wfile.write(f'{{"error":"{str(e)}"}}'.encode())
        else:
            self.send_response(404)
            self.end_headers()

    def _cors(self):
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")

if __name__ == "__main__":
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    server = HTTPServer(("0.0.0.0", PORT), Handler)
    print(f"✨ FamTask läuft auf http://0.0.0.0:{PORT}")
    print(f"   Daten werden gespeichert in: {DATA_FILE}")
    print(f"   Stoppen mit Ctrl+C\n")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nServer gestoppt.")
        sys.exit(0)

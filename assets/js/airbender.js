/**
 * Airbender.js - WebSocket Broadcasting Client
 *
 * A powerful, WebSocket client for Doppar framework
 * Supports public, private, and presence channels with automatic reconnection
 *
 * @version 1.0.0
 * @author Mahedi Hasan
 */

class Airbender {
  constructor(options = {}) {
    this.options = {
      host: options.host || "ws://127.0.0.1:6001",
      authEndpoint: options.authEndpoint || "/broadcasting/auth",
      authHeaders: options.authHeaders || {},
      encrypted: options.encrypted || false,
      reconnect: options.reconnect !== false,
      reconnectAttempts: options.reconnectAttempts || 5,
      reconnectInterval: options.reconnectInterval || 3000,
      ...options,
    };

    this.socket = null;
    this.socketId = null;
    this.channels = new Map();
    this.reconnectCount = 0;
    this.isConnected = false;
    this.messageQueue = [];
    this.listeners = new Map();
    this.isReady = false;
    this.readyQueue = [];
    this.connect();
  }

  /**
   * Establish WebSocket connection
   */
  connect() {
    const protocol = this.options.encrypted ? "wss" : "ws";
    const host = this.options.host.replace(/^(ws|wss):\/\//, "");
    const url = `${protocol}://${host}`;
    this.socket = new WebSocket(url);
    this.setupEventHandlers();
  }

  /**
   * Setup WebSocket event handlers
   */
  setupEventHandlers() {
    this.socket.onopen = () => {
      this.isConnected = true;
      this.reconnectCount = 0;

      // Process queued messages
      this.processMessageQueue();

      // Trigger connected event
      this.trigger("connected");
    };

    this.socket.onmessage = (event) => {
      this.handleMessage(JSON.parse(event.data));
    };

    this.socket.onerror = (error) => {
      console.error("[Doppar] WebSocket error:", error);
      this.trigger("error", error);
    };

    this.socket.onclose = () => {
      this.isConnected = false;
      this.socketId = null;
      this.trigger("disconnected");

      // Attempt reconnection
      if (
        this.options.reconnect &&
        this.reconnectCount < this.options.reconnectAttempts
      ) {
        this.reconnect();
      }
    };
  }

  /**
   * Handle incoming messages
   */
  handleMessage(message) {
    const { event, channel, data } = message;

    switch (event) {
      case "doppar:connection_established":
        this.handleConnectionEstablished(JSON.parse(data));
        break;

      case "doppar:subscription_succeeded":
        this.handleSubscriptionSucceeded(channel, data ? JSON.parse(data) : {});
        break;

      case "doppar:member_added":
        this.handleMemberAdded(channel, JSON.parse(data));
        break;

      case "doppar:member_removed":
        this.handleMemberRemoved(channel, JSON.parse(data));
        break;

      case "doppar:pong":
        // Heartbeat response
        break;

      case "doppar:error":
        console.error("[Doppar] Server error:", JSON.parse(data));
        this.trigger("error", JSON.parse(data));
        break;

      default:
        // User-defined event
        this.handleChannelEvent(channel, event, data);
        break;
    }
  }

  onReady(callback) {
    if (this.isReady) {
      callback(this.socketId);
    } else {
      this.readyQueue.push(() => callback(this.socketId));
    }
  }

  /**
   * Handle connection established
   */
  handleConnectionEstablished(data) {
    this.socketId = data.socket_id;
    this.isReady = true;

    // Trigger new "ready" event
    this.trigger("ready", this.socketId);

    // Process queued actions waiting for socket_id
    if (Array.isArray(this.readyQueue)) {
      this.readyQueue.forEach((cb) => cb());
      this.readyQueue = [];
    }

    // Resubscribe channels
    this.channels.forEach((channel) => channel.resubscribe());
  }

  /**
   * Handle subscription succeeded
   */
  handleSubscriptionSucceeded(channelName, payload) {
    const channel = this.channels.get(channelName);
    if (channel && !channel.subscribed) {
      channel.subscribed = true;
      channel.trigger("subscribed", payload);
    }
  }

  /**
   * Handle channel event
   */
  handleChannelEvent(channelName, event, data) {
    const channel = this.channels.get(channelName);
    if (channel) {
      channel.trigger(
        event,
        typeof data === "string" ? JSON.parse(data) : data
      );
    }
  }

  /**
   * Handle member added to presence channel
   */
  handleMemberAdded(channelName, data) {
    const channel = this.channels.get(channelName);
    if (channel && channel.type === "presence") {
      if (!channel.members.find((m) => m.user_id === data.user_id)) {
        channel.members.push(data);
      }

      channel.trigger("member-added", data);
    }
  }

  /**
   * Handle member removed from presence channel
   */
  handleMemberRemoved(channelName, data) {
    const channel = this.channels.get(channelName);
    if (channel && channel.type === "presence") {
      channel.members = channel.members.filter(
        (m) => m.user_id !== data.user_id
      );
      channel.trigger("member-removed", data);
    }
  }

  /**
   * Join a public channel
   */
  channel(channelName) {
    if (this.channels.has(channelName)) {
      return this.channels.get(channelName);
    }

    const channel = new AirbenderChannel(this, channelName, "public");
    this.channels.set(channelName, channel);
    channel.subscribe();

    return channel;
  }

  /**
   * Join a private channel
   */
  private(channelName) {
    const fullName = channelName.startsWith("private-")
      ? channelName
      : `private-${channelName}`;

    if (this.channels.has(fullName)) {
      return this.channels.get(fullName);
    }

    const channel = new AirbenderPrivateChannel(this, fullName);
    this.channels.set(fullName, channel);
    channel.subscribe();

    return channel;
  }

  /**
   * Join a presence channel
   */
  join(channelName) {
    const fullName = channelName.startsWith("presence-")
      ? channelName
      : `presence-${channelName}`;

    if (this.channels.has(fullName)) {
      return this.channels.get(fullName);
    }

    const channel = new AirbenderPresenceChannel(this, fullName);
    this.channels.set(fullName, channel);
    channel.subscribe();

    return channel;
  }

  /**
   * Leave a channel
   */
  leave(channelName) {
    const channel = this.channels.get(channelName);
    if (channel) {
      channel.clearListeners();
      channel.unsubscribe();
      this.channels.delete(channelName);
    }
  }

  /**
   * Send message to server
   */
  send(message) {
    if (this.socket && this.socket.readyState === WebSocket.OPEN) {
      this.socket.send(JSON.stringify(message));
    } else {
      this.messageQueue.push(message);
    }
  }

  /**
   * Process queued messages
   */
  processMessageQueue() {
    while (this.messageQueue.length > 0) {
      const queue = [...this.messageQueue];
      this.messageQueue = [];
      queue.forEach((msg) => this.send(msg));
    }
  }

  cleanup() {
    if (!this.socket) return;
    this.socket.onopen = null;
    this.socket.onmessage = null;
    this.socket.onerror = null;
    this.socket.onclose = null;
  }

  /**
   * Reconnect to server
   */
  reconnect() {
    this.cleanup();
    this.reconnectCount++;

    setTimeout(() => {
      this.connect();
    }, this.options.reconnectInterval);
  }

  /**
   * Disconnect from server
   */
  disconnect() {
    this.options.reconnect = false;

    // Unsubscribe from all channels
    this.channels.forEach((channel, name) => {
        channel.clearListeners();
        channel.unsubscribe();
    });
    this.channels.clear();

    // Clear global listeners
    this.listeners.clear();

    if (this.socket) {
      this.cleanup();
      this.socket.close();
    }
  }

  /**
   * Add global event listener
   */
  on(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, []);
    }
    this.listeners.get(event).push(callback);
    return this;
  }

  /**
   * Trigger global event
   */
  trigger(event, data = null) {
    const callbacks = this.listeners.get(event) || [];
    callbacks.forEach((callback) => callback(data));
  }

  /**
   * Get socket ID
   */
  getSocketId() {
    return this.socketId;
  }
}

/**
 * Base Channel Class
 */
class AirbenderChannel {
  constructor(doppar, name, type = "public") {
    this.doppar = doppar;
    this.name = name;
    this.type = type;
    this.subscribed = false;
    this.listeners = new Map();
  }

  /**
   * Subscribe to channel
   */
  subscribe() {
    this.doppar.onReady(() => {
      this.doppar.send({
        event: "doppar:subscribe",
        channel: this.name,
      });
    });
  }

  /**
   * Resubscribe to channel (after reconnection)
   */
  resubscribe() {
    if (this.subscribed) {
      this.subscribed = false;
      this.subscribe();
    }
  }

  /**
   * Unsubscribe from channel
   */
  unsubscribe() {
    this.doppar.send({
      event: "doppar:unsubscribe",
      channel: this.name,
    });
    this.subscribed = false;
  }

  /**
   * Listen for an event
   */
  listen(event, callback) {
    if (!this.listeners.has(event)) {
      this.listeners.set(event, []);
    }
    this.listeners.get(event).push(callback);
    return this;
  }

  /**
   * Trigger event callbacks
   */
  trigger(event, data = null) {
    const callbacks = this.listeners.get(event) || [];
    callbacks.forEach((callback) => callback(data));
  }

  /**
   * Clear all listeners
   */
  clearListeners() {
      this.listeners.clear();
      return this;
  }

  /**
   * Stop listening to an event
   */
  stopListening(event, callback = null) {
    if (!callback) {
      this.listeners.delete(event);
    } else {
      const callbacks = this.listeners.get(event) || [];
      this.listeners.set(
        event,
        callbacks.filter((cb) => cb !== callback)
      );
    }
    return this;
  }
}

/**
 * Private Channel Class
 */
class AirbenderPrivateChannel extends AirbenderChannel {
  constructor(doppar, name) {
    super(doppar, name, "private");
  }

  /**
   * Subscribe to private channel with authentication
   */
  async subscribe() {
    this.doppar.onReady(async () => {
      try {
        const auth = await this.authenticate();
        this.doppar.send({
          event: "doppar:subscribe",
          channel: this.name,
          data: auth,
        });
      } catch (error) {
        console.error(
          `[Doppar] Failed to authenticate for ${this.name}:`,
          error
        );
      }
    });
  }

  /**
   * Authenticate channel subscription
   */
  async authenticate() {
    const response = await fetch(this.doppar.options.authEndpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Socket-ID": this.doppar.getSocketId(),
        ...this.doppar.options.authHeaders,
      },
      body: JSON.stringify({
        socket_id: this.doppar.getSocketId(),
        channel_name: this.name,
      }),
    });

    if (!response.ok) {
      throw new Error("Authentication failed");
    }

    return await response.json();
  }

  /**
   * Whisper (send client event)
   */
  whisper(event, data) {
    this.doppar.send({
      event: "client-event",
      channel: this.name,
      data: {
        event: `client-${event}`,
        data: data,
      },
    });
    return this;
  }
}

/**
 * Presence Channel Class
 */
class AirbenderPresenceChannel extends AirbenderPrivateChannel {
  constructor(doppar, name) {
    super(doppar, name);
    this.type = "presence";
    this.members = [];
  }

  /**
   * Handle subscription success with member list
   */
  trigger(event, data) {
    if (event === "subscribed" && data && data.presence) {
      this.members = Object.keys(data.presence.hash).map((id) => ({
        user_id: id,
        user_info: data.presence.hash[id],
      }));
    }

    super.trigger(event, data);
  }

  /**
   * Get here (current members)
   */
  here(callback) {
    if (this.subscribed && this.members.length > 0) {
      callback(this.members);
    }

    return super.listen("subscribed", () => callback(this.members));
  }

  /**
   * Listen for joining members
   */
  joining(callback) {
    return this.listen("member-added", callback);
  }

  /**
   * Listen for leaving members
   */
  leaving(callback) {
    return this.listen("member-removed", callback);
  }
}

// Export for use in different environments
if (typeof module !== "undefined" && module.exports) {
  module.exports = Airbender;
}
if (typeof window !== "undefined") {
  window.Airbender = Airbender;
}

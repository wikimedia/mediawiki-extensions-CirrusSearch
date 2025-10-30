'use strict';
const fastify = require( 'fastify' );

class Server {
	constructor( options ) {
		this.server = fastify( {
			logger: false
		} );

		this.unixSocketPath = options.trackerPath;

		const globals = {
			tags: {},
			pending: {},
			resolvers: {}
		};

		this.server.post( '/tracker', async ( req, reply ) => {
			const data = req.body;

			if ( globals.resolvers[ data.complete ] ) {
				// tag completed, resolve pending
				globals.resolvers[ data.complete ]( {
					tag: data.complete,
					status: 'complete'
				} );
				return reply.send( data );
			}

			if ( globals.resolvers[ data.reject ] ) {
				globals.resolvers[ data.reject ]( {
					tag: data.reject,
					status: 'reject'
				} );
				return reply.send( data );
			}

			if ( globals.pending[ data.check ] ) {
				const sendData = await globals.pending[ data.check ];
				return reply.send( sendData );
			} else if ( data.check ) {
				globals.pending[ data.check ] = new Promise( ( resolve ) => {
					globals.resolvers[ data.check ] = resolve;
				} );
				return reply.send( {
					tag: data.check,
					status: 'new'
				} );
			} else {
				return reply.code( 400 ).send( {
					error: 'Unrecognized tag server request: ' + JSON.stringify( data )
				} );
			}
		} );
	}

	close() {
		return this.server.close();
	}

	start( success ) {
		this.server.listen( { path: this.unixSocketPath }, ( err ) => {
			if ( err ) {
				throw err;
			}
			if ( success ) {
				success();
			}
		} );
	}
}

( () => {
	let server;
	process.on( 'message', ( msg ) => {
		if ( msg.config ) {
			if ( server ) {
				process.send( { error: 'Already initialized' } );
			} else {
				server = new Server( msg.config );
				server.server.addHook( 'onError', async ( request, reply, error ) => {
					process.send( { error: error.message } );
				} );

				// Handle server initialization errors
				server.server.ready( ( err ) => {
					if ( err ) {
						process.send( { error: err.message } );
						server = undefined;
						return;
					}
				} );

				server.start(
					() => {
						console.log( 'Server initialized' );
						process.send( { initialized: true } );
					}
				);
			}
		}
		// TODO: figure out why the process channel is closed when cucumber tries to send
		// the exit signal...
		if ( msg.exit ) {
			console.log( 'tracker received shutdown message' );
			if ( server ) {
				server.close().then( () => {
					// The explicit design is to exit when requested by controlling process.
					process.exit();
				} ).catch( () => {
					process.exit( 1 );
				} );
			} else {
				process.exit();
			}
		}
	} );
} )();

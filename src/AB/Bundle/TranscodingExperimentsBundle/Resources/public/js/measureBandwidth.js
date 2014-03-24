function measureBandwidth(runTimeMs, callback) {
	var speeds = [];
	var totalData = 0;
	var runStart = new Date().getTime();
	var testStart = new Date().getTime();
	var runEnd = runStart+runTimeMs;
	var average = 0;

	function logTestResults(length, start, end) {
		var KBps = Math.round(100 * (length / ((end - start) / 1000)) / 1024) / 100;
		speeds.push(KBps);
		totalData += length;
		
		// Alternative method of calculating average speed, biased towards low speeds for small data sizes too much
		// average = eval(speeds.join('+')) / speeds.length;
		average = Math.round(100 * (totalData / ((end - runStart) / 1000)) / 1024) / 100;
		
		console.log( "Downloaded "+(length/1000) +" KB at: "+KBps+" KB/s, new average: "+average+" KB/s" );
		if(end > runEnd) return true;
		return false;
	}
		
	/*$.ajax( "servePayload/20/"+testStart ).done(function(msg) {
		if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);*/
		
		testStart = new Date().getTime();
		$.ajax( "servePayload/50/"+testStart ).done(function(msg) {
			if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
			
			testStart = new Date().getTime();
			$.ajax( "servePayload/100/"+testStart ).done(function(msg) {
				if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
		
				testStart = new Date().getTime();
				$.ajax( "servePayload/250/"+testStart ).done(function(msg) {
					if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
					
					testStart = new Date().getTime();
					$.ajax( "servePayload/500/"+testStart ).done(function(msg) {
						if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
						
						testStart = new Date().getTime();
						$.ajax( "servePayload/1000/"+testStart ).done(function(msg) {
							if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
							
							testStart = new Date().getTime();
							$.ajax( "servePayload/2000/"+testStart ).done(function(msg) {
								if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
																
								testStart = new Date().getTime();
								$.ajax( "servePayload/3000/"+testStart ).done(function(msg) {
									if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
									
									testStart = new Date().getTime();
									$.ajax( "servePayload/4000/"+testStart ).done(function(msg) {
										if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
										
										testStart = new Date().getTime();
										$.ajax( "servePayload/5000/"+testStart ).done(function(msg) {
											if( logTestResults(msg.length, testStart, new Date().getTime()) ) return callback(average);
										});
									});
								});
							});
						});
					});	
				});
			});	
		});
	//});	
}